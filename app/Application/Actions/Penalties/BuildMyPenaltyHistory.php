<?php

namespace App\Application\Actions\Penalties;

use App\Domain\Support\CurrentSemester;
use App\Models\AttendancePenalty;
use App\Models\Student;
use Illuminate\Support\Collection;

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
            ->with(['session.eventDay.event.semester.academicYear', 'reversedBy:id,first_name,last_name'])
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
                'semester_id' => $penalty->session->eventDay->event->semester_id,
            ])
            ->values()
            ->all();

        $total = $penalties
            ->where('is_reversed', false)
            ->sum(fn (AttendancePenalty $penalty) => (float) $penalty->amount);

        return [
            'total' => round($total, 2),
            'entries' => $entries,
            // Same penalties grouped semester -> event with subtotals (never
            // blended); `entries` stays the flat list existing clients read.
            'groups' => $this->group($penalties),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function group(Collection $penalties): array
    {
        $currentSemesterId = CurrentSemester::id();

        return $penalties
            ->groupBy(fn (AttendancePenalty $p) => $p->session->eventDay->event->semester_id ?? 0)
            ->map(function (Collection $forSemester, $semesterKey) use ($currentSemesterId) {
                $semester = $forSemester->first()->session->eventDay->event->semester;

                $events = $forSemester
                    ->groupBy(fn (AttendancePenalty $p) => $p->session->eventDay->event_id)
                    ->map(function (Collection $forEvent) {
                        $event = $forEvent->first()->session->eventDay->event;

                        return [
                            'event_id' => $event->id,
                            'event_name' => $event->name,
                            'subtotal' => round((float) $forEvent->where('is_reversed', false)->sum('amount'), 2),
                            'entries' => $forEvent->map(fn (AttendancePenalty $p) => [
                                'id' => $p->id,
                                'amount' => (float) $p->amount,
                                'reason' => $p->reason,
                                'is_reversed' => $p->is_reversed,
                                'reversed_by' => $p->reversedBy ? trim("{$p->reversedBy->first_name} {$p->reversedBy->last_name}") : null,
                                'reversed_at' => $p->reversed_at?->toIso8601String(),
                                'reversal_reason' => $p->reversal_reason,
                                'created_at' => $p->created_at->toIso8601String(),
                                'day_number' => $p->session->eventDay->day_number,
                                'date' => $p->session->eventDay->date->format('Y-m-d'),
                                'window_type' => $p->session->window_type->value,
                                'check_type' => $p->session->check_type->value,
                            ])->values()->all(),
                        ];
                    })->values();

                return [
                    'semester_id' => (int) $semesterKey === 0 ? null : (int) $semesterKey,
                    'semester_name' => $semester?->name->value,
                    'academic_year_name' => $semester?->academicYear?->name,
                    'is_current' => $currentSemesterId !== null && (int) $semesterKey === $currentSemesterId,
                    'subtotal' => round((float) $events->sum('subtotal'), 2),
                    'events' => $events->all(),
                ];
            })
            ->sortByDesc(fn (array $g) => [$g['is_current'] ? 1 : 0, $g['semester_id'] ?? 0])
            ->values()
            ->all();
    }
}
