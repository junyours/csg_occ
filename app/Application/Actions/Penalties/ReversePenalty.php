<?php

namespace App\Application\Actions\Penalties;

use App\Domain\Exceptions\PenaltyAlreadyReversedException;
use App\Models\AttendancePenalty;
use App\Models\PenaltyReversal;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class ReversePenalty
{
    /**
     * Spec §7.3: penalties should be "reversible (e.g. CSG manually
     * excuses a student after the fact)." Reversal is one-way — the spec
     * gives no path back to un-reversed, so this guards against
     * reversing the same penalty twice rather than silently letting a
     * second call overwrite who/when/why it was first excused.
     *
     * Two writes happen atomically:
     *  1. The penalty row is flagged (`is_reversed`, `reversed_by`,
     *     `reversal_reason`, `reversed_at`) so every place that already
     *     reads `is_reversed` — BuildMyPenaltyHistory, BuildDashboardSummary,
     *     the master/roster reports — excludes it from totals with no
     *     further changes on their end.
     *  2. An append-only `penalty_reversals` row is written — the audit
     *     trail counterpart to `role_assignments` — so "who excused what,
     *     when, and why" stays queryable independent of the mutable
     *     penalty row, for the audit-log export called out in spec §11.
     *
     * @throws PenaltyAlreadyReversedException
     */
    public function __invoke(AttendancePenalty $penalty, string $reason, Student $reversedBy): AttendancePenalty
    {
        return DB::transaction(function () use ($penalty, $reason, $reversedBy) {
            // Lock the row so two concurrent reversal requests for the
            // same penalty can't both pass the is_reversed check below —
            // same reasoning as EndSession's lockForUpdate on the session.
            $locked = AttendancePenalty::whereKey($penalty->id)->lockForUpdate()->first();

            if ($locked->is_reversed) {
                throw new PenaltyAlreadyReversedException;
            }

            $now = Carbon::now();

            $locked->update([
                'is_reversed' => true,
                'reversed_by' => $reversedBy->id,
                'reversal_reason' => $reason,
                'reversed_at' => $now,
            ]);

            PenaltyReversal::create([
                'attendance_penalty_id' => $locked->id,
                'student_id' => $locked->student_id,
                'reversed_by' => $reversedBy->id,
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }
}
