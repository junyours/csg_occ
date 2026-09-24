<?php

namespace App\Application\Actions\Penalties;

use App\Models\AttendancePenalty;
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

        $query->orderByDesc('created_at')
            // Tiebreaker: EndSession charges every absent student's
            // penalty inside one transaction (see EndSession), so a
            // whole batch routinely shares the same created_at to
            // second precision. Without a secondary key, "newest first"
            // degrades to whatever order the DB happens to return ties
            // in — id desc keeps it deterministic and still newest-first
            // within a tied batch, since ids are assigned in insert order.
            ->orderByDesc('id');

        $paginator = $query->paginate($filters['per_page'] ?? 25);

        $paginator->getCollection()->transform(fn (AttendancePenalty $penalty) => [
            'id' => $penalty->id,
            'student_id' => $penalty->student_id,
            'student_number' => $penalty->student->student_number,
            'last_name' => $penalty->student->last_name,
            'first_name' => $penalty->student->first_name,
            'department_id' => $penalty->student->department_id,
            'department_code' => $penalty->student->department?->code,
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
        ]);

        return $paginator;
    }
}
