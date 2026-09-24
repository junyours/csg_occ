<?php

namespace App\Application\Actions\Penalties;

use App\Models\AttendancePenalty;
use App\Models\Student;

final class BuildMyPenaltyHistory
{
    /**
     * One student's own penalty ledger, newest first — the auditing
     * counterpart to BuildMyAttendanceHistory: every amount ever charged
     * against this account, which session triggered it, why, and whether
     * it was later reversed and by whom. This is a read-only view of the
     * ledger, same as the dashboard's penalty total — nothing here can
     * create, reverse, or edit a penalty.
     *
     * `total` mirrors the dashboard's rule (BuildDashboardSummary::
     * buildStudentSummary): reversed penalties don't count against the
     * balance, so it's a sum over `entries` where is_reversed is false,
     * not a sum of every row returned.
     *
     * @return array{total: float, entries: list<array{
     *     id: int, amount: float, reason: string, is_reversed: bool,
     *     reversed_by: ?string, reversed_at: ?string, reversal_reason: ?string,
     *     created_at: string,
     *     event_id: int, event_name: string, day_number: int, date: string,
     *     window_type: string, check_type: string,
     * }>}
     */
    public function __invoke(Student $student): array
    {
        $penalties = AttendancePenalty::where('student_id', $student->id)
            ->with(['session.eventDay.event', 'reversedBy:id,first_name,last_name'])
            ->orderByDesc('created_at')
            ->get();

        $entries = $penalties
            ->map(fn (AttendancePenalty $penalty) => [
                'id' => $penalty->id,
                'amount' => (float) $penalty->amount,
                'reason' => $penalty->reason,
                'is_reversed' => $penalty->is_reversed,
                'reversed_by' => $penalty->reversedBy
                    ? trim("{$penalty->reversedBy->first_name} {$penalty->reversedBy->last_name}")
                    : null,
                'reversed_at' => $penalty->reversed_at?->toIso8601String(),
                'reversal_reason' => $penalty->reversal_reason,
                'created_at' => $penalty->created_at->toIso8601String(),
                'event_id' => $penalty->session->eventDay->event_id,
                'event_name' => $penalty->session->eventDay->event->name,
                'day_number' => $penalty->session->eventDay->day_number,
                'date' => $penalty->session->eventDay->date->format('Y-m-d'),
                'window_type' => $penalty->session->window_type->value,
                'check_type' => $penalty->session->check_type->value,
            ])
            ->values()
            ->all();

        $total = $penalties
            ->where('is_reversed', false)
            ->sum(fn (AttendancePenalty $penalty) => (float) $penalty->amount);

        return [
            'total' => round($total, 2),
            'entries' => $entries,
        ];
    }
}
