<?php

namespace App\Application\Actions\Exclusions;

use App\Domain\Enums\ExclusionScope;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Exclusion;
use Illuminate\Support\Collection;

/**
 * student-exclusion-feature-plan.md §6a point 1 — the "mid-window
 * exclusion" advisory.
 *
 * The *behaviour* the plan asks for is already enforced everywhere and
 * does not depend on this class: a student who genuinely scanned before
 * being excluded keeps that Present/Late record, ScanAttendance lets
 * them finish the check they started, EndSession never rewrites it, and
 * every report reads the real record ahead of the exclusion flag. What
 * was missing is the *heads-up*: the plan wants CSG told, at the moment
 * they add the exclusion, that it will not take effect for a window the
 * student has already timed in for.
 *
 * So this is deliberately read-only and advisory. It never blocks a
 * creation, never changes what was written, and an empty result is the
 * normal case. It runs after the exclusion row exists, so it reports on
 * exactly the scope that was actually committed rather than on what the
 * request asked for.
 *
 * Only sessions that have NOT ended are considered. An already-ended
 * session is outside an event-scope exclusion's forward-only cascade in
 * the first place (§6a's non-retroactivity, see
 * Exclusion::excludedStudentIdsForSession), so a record sitting on one
 * is not a collision worth warning about — nothing was ever going to
 * overwrite it.
 */
final class BuildExclusionWarnings
{
    private const WINDOW_LABELS = [
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'evening' => 'Evening',
    ];

    /**
     * @return array<int, string> Human-readable warnings, one per
     *                            already-scanned session the new
     *                            exclusion cannot take effect for.
     *                            Empty when there is nothing to say.
     */
    public function __invoke(Exclusion $exclusion): array
    {
        $sessions = $this->sessionsInScope($exclusion);

        if ($sessions->isEmpty()) {
            return [];
        }

        $scannedSessionIds = AttendanceRecord::whereIn('session_id', $sessions->pluck('id'))
            ->where('student_id', $exclusion->student_id)
            ->pluck('session_id')
            ->flip();

        return $sessions
            ->filter(fn (AttendanceSession $session) => $scannedSessionIds->has($session->id))
            ->map(fn (AttendanceSession $session) => sprintf(
                'This student already has a record for %s — the exclusion will not apply to it, and takes effect from the next window onward.',
                $this->label($session),
            ))
            ->values()
            ->all();
    }

    /**
     * The not-yet-ended sessions this exclusion actually covers, which
     * mirrors the three scopes in Exclusion::excludedStudentIdsForSession.
     *
     * @return Collection<int, AttendanceSession>
     */
    private function sessionsInScope(Exclusion $exclusion): Collection
    {
        $query = AttendanceSession::query()
            ->with('eventDay')
            ->whereNull('ended_at');

        return match ($exclusion->scope) {
            ExclusionScope::Event => $query
                ->whereHas('eventDay', fn ($q) => $q->where('event_id', $exclusion->event_id))
                ->get(),
            ExclusionScope::Day => $query
                ->where('event_day_id', $exclusion->event_day_id)
                ->get(),
            ExclusionScope::Window => $query
                ->where('event_day_id', $exclusion->event_day_id)
                ->where('window_type', $exclusion->window_type?->value)
                ->get(),
        };
    }

    private function label(AttendanceSession $session): string
    {
        return sprintf(
            'Day %d %s (%s)',
            $session->eventDay->day_number,
            self::WINDOW_LABELS[$session->window_type->value] ?? ucfirst($session->window_type->value),
            $session->check_type->value === 'time_out' ? 'Time Out' : 'Time In',
        );
    }
}
