<?php

namespace App\Domain\Support;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Who appears on a report. live(): current term — attendees who are active
 * OR already hold a record in the scoped sessions, live grouping columns.
 * historical(): record-driven, grouped by the per-record snapshot (falling
 * back per column to the live row); no status/role filter.
 */
final class ReportRoster
{
    public static function live(iterable $sessionIds, ?array $includedDepartmentIds, ?int $departmentId, ?string $major = null, ?string $yearLevel = null, ?string $section = null): Collection
    {
        $sessionIds = collect($sessionIds)->values()->all();

        return Student::attendees()
            ->when($includedDepartmentIds !== null, fn ($q) => $q->whereIn('department_id', $includedDepartmentIds))
            ->where(function ($q) use ($sessionIds) {
                $q->where('status', Student::STATUS_ACTIVE);

                if ($sessionIds !== []) {
                    $q->orWhereIn('id', AttendanceRecord::query()->select('student_id')->whereIn('session_id', $sessionIds));
                }
            })
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when(filled($major), fn ($q) => $q->where('major', $major))
            ->when(filled($yearLevel), fn ($q) => $q->where('year_level', $yearLevel))
            ->when(filled($section), fn ($q) => $q->where('section', $section))
            ->with('department')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
    }

    public static function historical(iterable $sessionIds, ?int $departmentId, ?string $major = null, ?string $yearLevel = null, ?string $section = null): Collection
    {
        $sessionIds = collect($sessionIds)->values()->all();

        if ($sessionIds === []) {
            return collect();
        }

        $firstRecordByStudent = AttendanceRecord::whereIn('session_id', $sessionIds)
            ->orderBy('id')
            ->get(['id', 'session_id', 'student_id', 'department_id', 'major', 'section', 'year_level'])
            ->groupBy('student_id')
            ->map(fn (Collection $records) => $records->first());

        if ($firstRecordByStudent->isEmpty()) {
            return collect();
        }

        $departments = Department::all()->keyBy('id');

        return Student::whereIn('id', $firstRecordByStudent->keys()->all())
            ->get()
            ->each(function (Student $student) use ($firstRecordByStudent, $departments) {
                $record = $firstRecordByStudent->get($student->id);

                $student->department_id = $record->department_id ?? $student->department_id;
                $student->major = $record->major ?? $student->major;
                $student->section = $record->section ?? $student->section;
                $student->year_level = $record->year_level ?? $student->year_level;
                $student->setRelation('department', $departments->get($student->department_id));
            })
            ->filter(fn (Student $s) => ! $departmentId || (int) $s->department_id === $departmentId)
            ->filter(fn (Student $s) => ! filled($major) || $s->major === $major)
            ->filter(fn (Student $s) => ! filled($yearLevel) || $s->year_level === $yearLevel)
            ->filter(fn (Student $s) => ! filled($section) || $s->section === $section)
            ->sortBy(fn (Student $s) => mb_strtolower($s->last_name.'|'.$s->first_name))
            ->values();
    }
}
