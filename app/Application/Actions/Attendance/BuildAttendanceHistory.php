<?php

namespace App\Application\Actions\Attendance;

use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class BuildAttendanceHistory
{
    /**
     * The audit trail the spec's "show who scan that attendance" asked
     * for: every AttendanceRecord (present/late/absent), who scanned it
     * and when, filterable the same way the student roster and penalty
     * ledger already are (course/major/year level/section), scoped to
     * one event or across all of them, and searchable against either
     * the attendee's name or the scanning officer's — mirrors
     * BuildPenaltyLedger's shape so the two admin ledgers read
     * consistently.
     *
     * Sorting by a related column (course, year level, section, major,
     * event/date) can't be done with plain whereHas ordering — Eloquent
     * has no orderByHas — so this joins students/sessions/event_days/
     * events explicitly rather than eager-loading them. `select` is
     * pinned to attendance_records.* to keep the join from leaking
     * ambiguous/duplicate id columns into the paginated result.
     *
     * @param array{
     *     department_id?: int, major?: string, year_level?: string, section?: string,
     *     event_id?: int, status?: 'all'|'present'|'late'|'absent'|'excluded',
     *     search?: string, sort?: 'recent'|'name'|'course'|'year_level'|'section'|'major'|'event',
     *     per_page?: int, scanned_by?: int,
     * } $filters
     */
    public function __invoke(array $filters): LengthAwarePaginator
    {
        $query = AttendanceRecord::query()
            ->select('attendance_records.*')
            ->with([
                'student.department',
                'scannedBy:id,first_name,last_name,role',
                'session.eventDay.event',
            ])
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.session_id')
            ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
            ->join('events', 'events.id', '=', 'event_days.event_id');

        if (! empty($filters['department_id'])) {
            $query->where('students.department_id', $filters['department_id']);
        }

        if (! empty($filters['major'])) {
            $query->where('students.major', $filters['major']);
        }

        if (! empty($filters['year_level'])) {
            $query->where('students.year_level', $filters['year_level']);
        }

        if (! empty($filters['section'])) {
            $query->where('students.section', $filters['section']);
        }

        // Officer scope (forced by the controller, never user-suppliable
        // beyond themselves): "who did I scan" rather than the full
        // ledger — the same records still show every other filter/sort
        // option, just pre-narrowed to this one scanner's rows.
        if (! empty($filters['scanned_by'])) {
            $query->where('attendance_records.scanned_by', $filters['scanned_by']);
        }

        // "All or per event" (spec): omitting event_id leaves every
        // event's records in scope; passing one narrows to it.
        if (! empty($filters['event_id'])) {
            $query->where('events.id', $filters['event_id']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('attendance_records.status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            // "who scan him" — search matches either the attendee or the
            // scanning officer, since an admin auditing a suspicious scan
            // might start from either name.
            $query->where(function (Builder $outer) use ($term) {
                $outer->where('students.student_number', 'like', $term)
                    ->orWhere('students.last_name', 'like', $term)
                    ->orWhere('students.first_name', 'like', $term)
                    ->orWhereHas('scannedBy', function (Builder $scannerQuery) use ($term) {
                        $scannerQuery->where('last_name', 'like', $term)
                            ->orWhere('first_name', 'like', $term);
                    });
            });
        }

        match ($filters['sort'] ?? 'recent') {
            'name' => $query->orderBy('students.last_name')->orderBy('students.first_name'),
            'course' => $query->orderBy('students.department_id')
                ->orderBy('students.last_name')->orderBy('students.first_name'),
            'year_level' => $query->orderBy('students.year_level')
                ->orderBy('students.last_name')->orderBy('students.first_name'),
            'section' => $query->orderBy('students.section')
                ->orderBy('students.last_name')->orderBy('students.first_name'),
            'major' => $query->orderBy('students.major')
                ->orderBy('students.last_name')->orderBy('students.first_name'),
            'event' => $query->orderBy('events.id', 'desc')->orderBy('event_days.day_number')
                ->orderBy('students.last_name')->orderBy('students.first_name'),
            // Default: newest scan first — the natural "what just
            // happened" audit view. Tiebreaker on id for determinism
            // when scans share a scanned_at second (a rush of students
            // tapping through the gate at once).
            default => $query->orderByDesc('attendance_records.scanned_at')->orderByDesc('attendance_records.id'),
        };

        $paginator = $query->paginate($filters['per_page'] ?? 25);

        $paginator->getCollection()->transform(fn (AttendanceRecord $record) => [
            'id' => $record->id,
            'student_id' => $record->student_id,
            'student_number' => $record->student->student_number,
            'last_name' => $record->student->last_name,
            'first_name' => $record->student->first_name,
            'department_id' => $record->student->department_id,
            'department_code' => $record->student->department?->code,
            // Same reasoning as BuildPenaltyLedger: the admin ledger shows
            // the attendee's photo, so the row carries the URL directly
            // rather than making the frontend re-fetch each student.
            'photo_url' => $record->student->photo_url,
            'major' => $record->student->major,
            'year_level' => $record->student->year_level,
            'section' => $record->student->section,
            'status' => $record->status->value,
            'scanned_at' => $record->scanned_at?->toIso8601String(),
            'scanned_by' => $record->scanned_by,
            'scanned_by_name' => $record->scannedBy
                ? trim("{$record->scannedBy->first_name} {$record->scannedBy->last_name}")
                : null,
            'scanned_by_role' => $record->scannedBy?->role?->value,
            'event_id' => $record->session->eventDay->event_id,
            'event_name' => $record->session->eventDay->event->name,
            'day_number' => $record->session->eventDay->day_number,
            'date' => $record->session->eventDay->date->format('Y-m-d'),
            'window_type' => $record->session->window_type->value,
            'check_type' => $record->session->check_type->value,
        ]);

        return $paginator;
    }
}
