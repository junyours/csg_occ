<?php

namespace App\Application\Actions\Penalties;

use App\Domain\Support\CurrentSemester;
use App\Domain\Support\SemesterScope;
use App\Models\AttendancePenalty;

final class BuildPenaltySummary
{
    /**
     * The totals strip above BuildPenaltyLedger's table — same filters
     * (department, event, status, search), same rows, just aggregated
     * instead of paginated. Kept as its own query rather than derived
     * from the paginator's current page, since a page only ever holds
     * up to `per_page` rows and the totals need to cover every row the
     * filters match, not just what's currently on screen.
     *
     * `total` sums whatever the filters currently select — including
     * reversed penalties when status is 'reversed' or 'all' — so the
     * number on screen always matches the rows listed under it. This is
     * distinct from BuildDashboardSummary::penaltyTotal, which always
     * excludes reversed penalties because it reports an outstanding
     * balance rather than a filtered total.
     *
     * @param array{
     *     department_id?: int, event_id?: int,
     *     status?: 'all'|'active'|'reversed',
     *     search?: string,
     * } $filters
     * @return array{total: float, absentCount: int, lateCount: int, count: int, bySemester?: list<array>}
     *         `bySemester` is only present when scope=all.
     */
    public function __invoke(array $filters): array
    {
        $query = AttendancePenalty::query();

        SemesterScope::penalties(
            $query,
            ($filters['scope'] ?? null) === 'all' ? null : ($filters['semester_id'] ?? CurrentSemester::id()),
        );

        if (! empty($filters['department_id'])) {
            SemesterScope::penaltyDepartment($query, (int) $filters['department_id']);
        }

        if (! empty($filters['event_id'])) {
            $eventId = $filters['event_id'];
            $query->whereHas('session.eventDay', fn ($q) => $q->where('event_id', $eventId));
        }

        match ($filters['status'] ?? 'all') {
            'active' => $query->where('is_reversed', false),
            'reversed' => $query->where('is_reversed', true),
            default => null,
        };

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->whereHas('student', fn ($q) => $q->where('student_number', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('first_name', 'like', $term));
        }

        // Clone for every aggregate below rather than reusing $query
        // directly, so each call runs against the same filtered set
        // independent of what an earlier aggregate call did to it.
        $summary = [
            'total' => (float) (clone $query)->sum('amount'),
            'absentCount' => (clone $query)->where('reason', 'like', 'Missed%')->count(),
            'lateCount' => (clone $query)->where('reason', 'like', 'Late%')->count(),
            'count' => (clone $query)->count(),
        ];

        // "All semesters" never merges into one number: each semester gets
        // its own subtotal over the same filtered rows.
        if (($filters['scope'] ?? null) === 'all') {
            $summary['bySemester'] = $this->bySemester(clone $query);
        }

        return $summary;
    }

    /**
     * @return list<array{semester_id: ?int, semester_name: ?string, academic_year_name: ?string, is_current: bool, total: float, absentCount: int, lateCount: int, count: int}>
     */
    private function bySemester($query): array
    {
        $rows = $query->toBase()
            ->selectRaw(SemesterScope::PENALTY_SEMESTER.' as sem_id')
            ->selectRaw('COALESCE(SUM(amount), 0) as total')
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw("SUM(CASE WHEN reason LIKE 'Missed%' THEN 1 ELSE 0 END) as absent")
            ->selectRaw("SUM(CASE WHEN reason LIKE 'Late%' THEN 1 ELSE 0 END) as late")
            ->groupBy('sem_id')
            ->get();

        $labels = SemesterScope::labels($rows->pluck('sem_id'));
        $currentId = CurrentSemester::id();

        return $rows
            ->map(function ($row) use ($labels, $currentId) {
                $id = $row->sem_id !== null ? (int) $row->sem_id : null;

                return [
                    'semester_id' => $id,
                    'semester_name' => $id !== null ? ($labels[$id]['semester_name'] ?? null) : null,
                    'academic_year_name' => $id !== null ? ($labels[$id]['academic_year_name'] ?? null) : null,
                    'is_current' => $id !== null && $id === $currentId,
                    'total' => (float) $row->total,
                    'absentCount' => (int) $row->absent,
                    'lateCount' => (int) $row->late,
                    'count' => (int) $row->cnt,
                ];
            })
            ->sortByDesc(fn (array $group) => $group['semester_id'] ?? 0)
            ->values()
            ->all();
    }
}
