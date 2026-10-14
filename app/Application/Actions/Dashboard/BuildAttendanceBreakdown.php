<?php

namespace App\Application\Actions\Dashboard;

use App\Domain\Enums\SessionStatus;
use App\Domain\Support\SemesterScope;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class BuildAttendanceBreakdown
{
    /**
     * Present / Late / Missed / Excluded as plain COUNTS — overall and per
     * department — for ONE event (optionally narrowed to one day of it), or,
     * when neither is given, for every event in the semester scope.
     *
     * This replaces the dashboard's old all-time-everything totals, which
     * blended every event ever run into one number that didn't answer any
     * real question. The admin dashboard now opens on the active event and
     * lets the admin switch event / day.
     *
     * Where the numbers come from:
     *  - Every session that has ENDED has a permanent attendance_records row
     *    per tracked student, including `excluded` ones (EndSession writes
     *    them), so those are a plain grouped count.
     *  - A session still ONGOING has no row yet for a student who is excluded
     *    from it and hasn't scanned, so those are added live from the
     *    exclusions table (the same rule BuildDashboardSummary::sessionCounts
     *    uses). Only ongoing sessions pay this cost — normally zero to two.
     *  - Missed is never guessed: it only exists once EndSession has run.
     *
     * Department comes from each record's frozen snapshot, like the rest of
     * the dashboard. `tracked` = present + late + missed; Excluded is shown
     * beside it but is not part of it (an excluded student was never expected
     * to attend), so the percentage-of-overall share is over `tracked`.
     *
     * @param  ?int  $departmentId  SC Admin's own department; null = all.
     * @param  ?int  $semesterId  Only used when no event/day is given.
     * @return array<string, mixed>
     */
    public function __invoke(?int $departmentId, ?int $eventId, ?int $eventDayId, ?int $semesterId): array
    {
        $bySemester = $eventId === null && $eventDayId === null;

        $recorded = SemesterScope::records(
            DB::table('attendance_records')
                ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_records.session_id')
                ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
                ->join('students', 'students.id', '=', 'attendance_records.student_id')
                ->when($eventId, fn ($q) => $q->where('event_days.event_id', $eventId))
                ->when($eventDayId, fn ($q) => $q->where('event_days.id', $eventDayId))
                ->when($departmentId, fn ($q) => $q->whereRaw(SemesterScope::RECORD_DEPARTMENT.' = ?', [$departmentId]))
                ->select(
                    DB::raw(SemesterScope::RECORD_DEPARTMENT.' as department_id'),
                    DB::raw("sum(case when attendance_records.status = 'present' then 1 else 0 end) as present"),
                    DB::raw("sum(case when attendance_records.status = 'late' then 1 else 0 end) as late"),
                    DB::raw("sum(case when attendance_records.status = 'missed' then 1 else 0 end) as missed"),
                    DB::raw("sum(case when attendance_records.status = 'excluded' then 1 else 0 end) as excluded"),
                )
                ->groupBy(DB::raw(SemesterScope::RECORD_DEPARTMENT)),
            $bySemester ? $semesterId : null,
        )->get();

        /** @var array<int, array{present: int, late: int, missed: int, excluded: int}> $perDepartment */
        $perDepartment = [];

        foreach ($recorded as $row) {
            if ($row->department_id === null) {
                continue;
            }

            $perDepartment[(int) $row->department_id] = [
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'missed' => (int) $row->missed,
                'excluded' => (int) $row->excluded,
            ];
        }

        foreach ($this->liveExcludedByDepartment($departmentId, $eventId, $eventDayId, $bySemester ? $semesterId : null) as $deptId => $count) {
            $perDepartment[$deptId] ??= ['present' => 0, 'late' => 0, 'missed' => 0, 'excluded' => 0];
            $perDepartment[$deptId]['excluded'] += $count;
        }

        $departments = Department::whereIn('id', array_keys($perDepartment))->get()->keyBy('id');

        $overallTracked = 0;
        foreach ($perDepartment as $counts) {
            $overallTracked += $counts['present'] + $counts['late'] + $counts['missed'];
        }

        $overall = ['present' => 0, 'late' => 0, 'missed' => 0, 'excluded' => 0];

        $byDepartment = collect($perDepartment)
            ->map(function (array $counts, int $deptId) use ($departments, $overallTracked, &$overall) {
                $department = $departments->get($deptId);
                $tracked = $counts['present'] + $counts['late'] + $counts['missed'];

                foreach ($counts as $status => $value) {
                    $overall[$status] += $value;
                }

                return [
                    'department_id' => $deptId,
                    'department_name' => $department?->name ?? 'Unknown',
                    'department_code' => $department?->code ?? '—',
                    'department_logo_url' => $department?->logo_path
                        ? Storage::disk('public')->url($department->logo_path)
                        : null,
                    'present' => $counts['present'],
                    'late' => $counts['late'],
                    'missed' => $counts['missed'],
                    'excluded' => $counts['excluded'],
                    'tracked' => $tracked,
                    'percentage_of_overall' => $overallTracked > 0 ? round($tracked / $overallTracked * 100, 2) : 0.0,
                ];
            })
            ->sort(fn (array $a, array $b) => [$b['tracked'], $b['excluded']] <=> [$a['tracked'], $a['excluded']])
            ->values()
            ->all();

        return [
            'event_id' => $eventId,
            'event_day_id' => $eventDayId,
            'overall' => $overall,
            'by_department' => $byDepartment,
        ];
    }

    /**
     * Excluded students of sessions still ongoing inside the requested
     * scope who have no record yet, counted per department.
     *
     * @return array<int, int> department_id => count
     */
    private function liveExcludedByDepartment(?int $departmentId, ?int $eventId, ?int $eventDayId, ?int $semesterId): array
    {
        $sessions = AttendanceSession::with('eventDay.event')
            ->where('status', SessionStatus::Ongoing->value)
            ->whereHas('eventDay', fn ($q) => $q
                ->when($eventId, fn ($w) => $w->where('event_id', $eventId))
                ->when($eventDayId, fn ($w) => $w->where('id', $eventDayId))
                ->when($semesterId !== null, fn ($w) => $w->whereHas(
                    'event',
                    fn ($event) => SemesterScope::events($event, $semesterId)
                )))
            ->get();

        $counts = [];

        foreach ($sessions as $session) {
            $excludedIds = Exclusion::excludedStudentIdsForSession($session);

            if ($excludedIds === []) {
                continue;
            }

            $alreadyRecorded = AttendanceRecord::where('session_id', $session->id)
                ->whereIn('student_id', $excludedIds)
                ->pluck('student_id')
                ->all();

            $pendingIds = array_values(array_diff($excludedIds, $alreadyRecorded));

            if ($pendingIds === []) {
                continue;
            }

            $students = Student::attendees()
                ->activeAccounts()
                ->whereIn('id', $pendingIds)
                ->whereIn('department_id', $session->eventDay->event->includedDepartmentIds())
                ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
                ->get(['id', 'department_id']);

            foreach ($students as $student) {
                $counts[$student->department_id] = ($counts[$student->department_id] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
