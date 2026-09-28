<?php

namespace App\Application\Actions\Penalties;

use App\Domain\Support\CurrentSemester;
use App\Domain\Support\SemesterScope;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\Department;
use Illuminate\Pagination\LengthAwarePaginator;

final class BuildPenaltyLedger
{
    /**
     * The admin counterpart to BuildMyPenaltyHistory: every penalty
     * across every student, newest first, filterable by department,
     * event, reversed-state, and a name/number search — the worklist a
     * CSG Admin reads before deciding what to reverse (spec §7.3).
     *
     * Unlike BuildMyPenaltyHistory, is_reversed is never used to exclude
     * rows here — an admin needs to see already-reversed penalties too
     * (e.g. to confirm a past reversal went through), so filtering on
     * reversed state is opt-in via `status`, not baked into the query.
     *
     * @param array{
     *     department_id?: int, event_id?: int,
     *     status?: 'all'|'active'|'reversed',
     *     search?: string, per_page?: int,
     * } $filters
     */
    public function __invoke(array $filters): LengthAwarePaginator
    {
        $query = AttendancePenalty::query()
            ->with(['student.department', 'session.eventDay.event', 'reversedBy:id,first_name,last_name']);

        SemesterScope::penalties(
            $query,
            ($filters['scope'] ?? null) === 'all' ? null : ($filters['semester_id'] ?? CurrentSemester::id()),
        );

        // Department as of when it was charged (record snapshot).
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

        // "All semesters" is shown grouped by semester, so rows of one
        // semester must sit together (newest semester first).
        if (($filters['scope'] ?? null) === 'all') {
            $query->orderByRaw(SemesterScope::PENALTY_SEMESTER.' DESC');
        }

        $query->orderByDesc('created_at')
            // Tiebreaker: EndSession charges every missed student's
            // penalty inside one transaction (see EndSession), so a
            // whole batch routinely shares the same created_at to
            // second precision. Without a secondary key, "newest first"
            // degrades to whatever order the DB happens to return ties
            // in — id desc keeps it deterministic and still newest-first
            // within a tied batch, since ids are assigned in insert order.
            ->orderByDesc('id');

        $paginator = $query->paginate($filters['per_page'] ?? 25);

        $records = AttendanceRecord::whereIn('session_id', $paginator->getCollection()->pluck('session_id')->unique()->values())
            ->whereIn('student_id', $paginator->getCollection()->pluck('student_id')->unique()->values())
            ->get(['session_id', 'student_id', 'department_id'])
            ->keyBy(fn (AttendanceRecord $r) => $r->session_id.'-'.$r->student_id);
        $departmentCodes = Department::pluck('code', 'id');

        $paginator->getCollection()->transform(function (AttendancePenalty $penalty) use ($records, $departmentCodes) {
            $snapshotDepartmentId = $records->get($penalty->session_id.'-'.$penalty->student_id)?->department_id
                ?? $penalty->student->department_id;

            return [
                'id' => $penalty->id,
                'student_id' => $penalty->student_id,
                'student_number' => $penalty->student->student_number,
                'last_name' => $penalty->student->last_name,
                'first_name' => $penalty->student->first_name,
                'department_id' => $snapshotDepartmentId,
                'department_code' => $departmentCodes->get($snapshotDepartmentId),
                // The ledger renders the student's own photo rather than a
                // generic icon (see penalties-page.tsx's UserAvatar), so the
                // row has to carry the same ready-to-use URL the students
                // list already returns. Null when no photo is on file — the
                // frontend falls back to initials.
                'photo_url' => $penalty->student->photo_url,
                'amount' => (float) $penalty->amount,
                'reason' => $penalty->reason,
                'is_reversed' => $penalty->is_reversed,
                'reversed_by' => $penalty->reversed_by,
                'reversed_by_name' => $penalty->reversedBy
                    ? trim("{$penalty->reversedBy->first_name} {$penalty->reversedBy->last_name}")
                    : null,
                'reversal_reason' => $penalty->reversal_reason,
                'reversed_at' => $penalty->reversed_at?->toIso8601String(),
                'created_at' => $penalty->created_at->toIso8601String(),
                'event_id' => $penalty->session->eventDay->event_id,
                'event_name' => $penalty->session->eventDay->event->name,
                'day_number' => $penalty->session->eventDay->day_number,
                'date' => $penalty->session->eventDay->date->format('Y-m-d'),
                'window_type' => $penalty->session->window_type->value,
                'check_type' => $penalty->session->check_type->value,
                'semester_id' => $penalty->session->eventDay->event->semester_id,
            ];
        });

        return $paginator;
    }
}
