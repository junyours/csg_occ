<?php

namespace App\Domain\Support;

use App\Domain\Enums\Role;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Shared semester filters, tolerant of legacy rows (no semester anywhere
 * stays visible). A null $semesterId means "no filter".
 */
final class SemesterScope
{
    public const RECORD_DEPARTMENT = 'COALESCE(attendance_records.department_id, students.department_id)';

    /** A record's semester: its snapshot, else its event's (needs the `events` join). */
    public const RECORD_SEMESTER = 'COALESCE(attendance_records.semester_id, events.semester_id)';

    /** A penalty's semester, taken from its event (correlated to `attendance_penalties`). */
    public const PENALTY_SEMESTER = '(SELECT e.semester_id FROM attendance_sessions s '
        .'JOIN event_days d ON d.id = s.event_day_id '
        .'JOIN events e ON e.id = d.event_id '
        .'WHERE s.id = attendance_penalties.session_id)';

    /**
     * Display labels for the semesters an "All semesters" summary lists.
     *
     * @param  iterable<int|string|null>  $ids
     * @return array<int, array{semester_name: ?string, academic_year_name: ?string}>
     */
    public static function labels(iterable $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return Semester::with('academicYear')->whereIn('id', $ids)->get()
            ->mapWithKeys(fn (Semester $semester) => [$semester->id => [
                'semester_name' => $semester->name->value,
                'academic_year_name' => $semester->academicYear?->name,
            ]])
            ->all();
    }

    public static function resolve(Student $user, mixed $scope, mixed $semesterId): ?int
    {
        if ($user->role === Role::Student) {
            return CurrentSemester::id();
        }

        if ($scope === 'all') {
            return null;
        }

        if ($semesterId !== null && $semesterId !== '') {
            return (int) $semesterId;
        }

        return CurrentSemester::id();
    }

    /** Restrict a query on `attendance_records` to one semester. */
    public static function records($query, ?int $semesterId)
    {
        if ($semesterId === null) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($semesterId) {
            $sub->select(DB::raw(1))
                ->from('attendance_sessions')
                ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
                ->join('events', 'events.id', '=', 'event_days.event_id')
                ->whereColumn('attendance_sessions.id', 'attendance_records.session_id')
                ->where(function ($w) use ($semesterId) {
                    $w->whereRaw('COALESCE(attendance_records.semester_id, events.semester_id) = ?', [$semesterId])
                        ->orWhere(function ($legacy) {
                            $legacy->whereNull('attendance_records.semester_id')
                                ->whereNull('events.semester_id');
                        });
                });
        });
    }

    /** Restrict a query on `attendance_penalties` to one semester (via the event). */
    public static function penalties($query, ?int $semesterId)
    {
        if ($semesterId === null) {
            return $query;
        }

        return $query->whereExists(function ($sub) use ($semesterId) {
            $sub->select(DB::raw(1))
                ->from('attendance_sessions')
                ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
                ->join('events', 'events.id', '=', 'event_days.event_id')
                ->whereColumn('attendance_sessions.id', 'attendance_penalties.session_id')
                ->where(function ($w) use ($semesterId) {
                    $w->where('events.semester_id', $semesterId)->orWhereNull('events.semester_id');
                });
        });
    }

    public static function events($query, ?int $semesterId, string $column = 'semester_id')
    {
        if ($semesterId === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w->where($column, $semesterId)->orWhereNull($column));
    }

    /** Penalty's department: record snapshot, else the student's live department. */
    public static function penaltyDepartment($query, int $departmentId)
    {
        return $query->whereRaw(
            'COALESCE('
            .'(SELECT ar.department_id FROM attendance_records ar WHERE ar.session_id = attendance_penalties.session_id AND ar.student_id = attendance_penalties.student_id), '
            .'(SELECT s.department_id FROM students s WHERE s.id = attendance_penalties.student_id)'
            .') = ?',
            [$departmentId],
        );
    }
}
