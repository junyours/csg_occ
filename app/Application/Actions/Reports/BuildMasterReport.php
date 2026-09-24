<?php

namespace App\Application\Actions\Reports;

use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Settings → Reports landing screen (spec: clicking "Reports" shows
 * every event for the current active academic year and semester, with
 * total Present, Absent, Late, and Excluded) — a birds-eye summary,
 * unlike BuildEventRosterReport which drills into one event's printable
 * per-student roster.
 */
final class BuildMasterReport
{
    /**
     * @param  int|null  $departmentId  An SC Admin's own department,
     *                                  forced by the controller; null
     *                                  counts every department.
     * @return array{
     *     academic_year: ?array{id: int, name: string},
     *     semester: ?array{id: int, name: string},
     *     events: array<int, array{id: int, name: string, status: string, present: int, late: int, absent: int, excluded: int}>,
     * }
     */
    public function __invoke(?int $departmentId = null): array
    {
        $academicYear = AcademicYear::active()->first();
        $semester = $academicYear
            ? Semester::active()->forAcademicYear($academicYear->id)->first()
            : null;

        if (! $academicYear || ! $semester) {
            return [
                'academic_year' => $academicYear ? ['id' => $academicYear->id, 'name' => $academicYear->name] : null,
                'semester' => null,
                'events' => [],
            ];
        }

        // Loaded once per department_id, not once per event id/department
        // pair — summarizeEvent() below intersects this with each event's
        // own includedDepartmentIds() instead of re-querying.
        $roster = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->get(['id', 'department_id']);

        // Eager-load departments so includedDepartmentIds() below doesn't
        // fire a query per event.
        $events = EventModel::forSemester($semester->id)->with('departments')->orderBy('id')->get();

        return [
            'academic_year' => ['id' => $academicYear->id, 'name' => $academicYear->name],
            'semester' => ['id' => $semester->id, 'name' => $semester->name->value],
            'events' => $events->map(fn (EventModel $event) => $this->summarizeEvent($event, $roster))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Student>  $roster  Every eligible student
     *                                             (already filtered by the
     *                                             viewer's own department,
     *                                             if any) — narrowed here
     *                                             to this event's scope.
     * @return array{id: int, name: string, status: string, present: int, late: int, absent: int, excluded: int}
     */
    private function summarizeEvent(EventModel $event, Collection $roster): array
    {
        // Never counts a student whose department isn't part of this
        // event's scope (see EventModel::includedDepartmentIds) — same
        // reasoning as BuildSessionReport / BuildEventRosterReport.
        $includedDepartmentIds = $event->includedDepartmentIds();
        $rosterIds = $roster
            ->filter(fn (Student $student) => in_array($student->department_id, $includedDepartmentIds, true))
            ->pluck('id');

        $sessions = AttendanceSession::whereHas('eventDay', fn ($q) => $q->where('event_id', $event->id))->get();
        $sessionIds = $sessions->pluck('id');

        // Plain DB query builder, not Eloquent — grouping by a column
        // that's cast to an enum (AttendanceRecord::$casts) would hand
        // pluck() an enum instance as its array key, which fails. Raw
        // rows sidestep that entirely.
        $statusCounts = DB::table('attendance_records')
            ->whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // A session ended the normal way (through EndSession) already
        // has a real attendance_records row — present, late, absent, or
        // a permanently-persisted excluded row (see
        // EndSession::markMissingRecords) — for every roster student, so
        // $statusCounts above already reflects it correctly and
        // permanently, even if the exclusion behind an 'excluded' row is
        // later removed. This sweep only needs to independently account
        // for students with no record *at all* yet: an unscanned student
        // in a still-open session (stays "pending", not tallied here),
        // or — for a session marked ended without ever having gone
        // through EndSession — the students that action would have
        // written a row for.
        $recordedStudentIdsBySession = DB::table('attendance_records')
            ->whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->select('session_id', 'student_id')
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $rows) => $rows->pluck('student_id'));

        // Seeded from the persisted counts above so a student already
        // holding a real 'excluded' row is never double-counted by the
        // live sweep below.
        $excludedTotal = (int) ($statusCounts['excluded'] ?? 0);
        $unrecordedAbsentTotal = 0;

        foreach ($sessions as $session) {
            $recordedIds = $recordedStudentIdsBySession->get($session->id, collect());

            // A student can be both "currently excluded" and already
            // holding a real record for this session — the mid-window
            // guard case (student-exclusion-feature-plan.md §2 rule 4):
            // they scanned before being excluded, so that real
            // Present/Late outcome already landed in $statusCounts above
            // and must not also be tallied here as Excluded.
            $excludedIds = Exclusion::excludedStudentIdsForSession($session);
            $sessionExcludedIds = $rosterIds->intersect($excludedIds)->diff($recordedIds);
            $excludedTotal += $sessionExcludedIds->count();

            if ($session->status === SessionStatus::Ended) {
                $unrecordedAbsentTotal += $rosterIds
                    ->diff($sessionExcludedIds)
                    ->diff($recordedIds)
                    ->count();
            }
        }

        return [
            'id' => $event->id,
            'name' => $event->name,
            'status' => $event->status->value,
            'present' => (int) ($statusCounts['present'] ?? 0),
            'late' => (int) ($statusCounts['late'] ?? 0),
            'absent' => (int) ($statusCounts['absent'] ?? 0) + $unrecordedAbsentTotal,
            'excluded' => $excludedTotal,
        ];
    }
}
