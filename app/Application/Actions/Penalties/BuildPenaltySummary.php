<?php

namespace App\Application\Actions\Penalties;

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
     * @return array{total: float, absentCount: int, lateCount: int, count: int}
     */
    public function __invoke(array $filters): array
    {
        $query = AttendancePenalty::query();

        if (! empty($filters['department_id'])) {
            $departmentId = $filters['department_id'];
            $query->whereHas('student', fn ($q) => $q->where('department_id', $departmentId));
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
        return [
            'total' => (float) (clone $query)->sum('amount'),
            'absentCount' => (clone $query)->where('reason', 'like', 'Absent%')->count(),
            'lateCount' => (clone $query)->where('reason', 'like', 'Late%')->count(),
            'count' => (clone $query)->count(),
        ];
    }
}
