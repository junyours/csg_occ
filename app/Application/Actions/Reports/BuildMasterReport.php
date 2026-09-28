<?php

namespace App\Application\Actions\Reports;

use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Support\CurrentSemester;
use App\Domain\Support\SemesterScope;
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
 * total Present, Missed, Late, and Excluded) — a birds-eye summary,
 * unlike BuildEventRosterReport which drills into one event's printable
 * per-student roster.
 */
final class BuildMasterReport
{
    /**
     * @param  int|null  $departmentId  SC Admin's own department (forced by controller).
     * @param  int|null  $semesterId  A specific semester; null = current active one.
     */
    public function __invoke(?int $departmentId = null, ?int $semesterId = null): array
    {
        if ($semesterId !== null) {
            $semester = Semester::with('academicYear')->find($semesterId);
            $academicYear = $semester?->academicYear;
        } else {
            // The one shared "active year + active semester" lookup. The
            // year is still looked up on its own when no semester is
            // active, so the empty report keeps naming the active year.
            $semester = CurrentSemester::get()?->loadMissing('academicYear');
            $academicYear = $semester?->academicYear ?? AcademicYear::active()->first();
        }

        if (! $academicYear || ! $semester) {
            return [
                'academic_year' => $academicYear ? ['id' => $academicYear->id, 'name' => $academicYear->name] : null,
                'semester' => null,
                'is_current' => false,
                'events' => [],
            ];
        }

        // Current term: live roster. Past term: record-driven (snapshot department).
        $live = CurrentSemester::isCurrent($semester->id);

        $people = $live
            ? Student::attendees()
                ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
                ->get(['id', 'department_id', 'role', 'status'])
            : collect();

        $events = EventModel::forSemester($semester->id)->with('departments')->orderBy('id')->get();

        return [
            'academic_year' => ['id' => $academicYear->id, 'name' => $academicYear->name],
            'semester' => ['id' => $semester->id, 'name' => $semester->name->value],
            'is_current' => $live,
            'events' => $events
                ->map(fn (EventModel $event) => $this->summarizeEvent($event, $people, $live, $departmentId))
                ->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Student>  $roster  Every eligible student
     *                                             (already filtered by the
     *                                             viewer's own department,
     *                                             if any) — narrowed here
     *                                             to this event's scope.
     * @return array{id: int, name: string, status: string, present: int, late: int, missed: int, excluded: int}
     */
    private function summarizeEvent(EventModel $event, Collection $people, bool $live, ?int $departmentId): array
    {
        $sessions = AttendanceSession::whereHas('eventDay', fn ($q) => $q->where('event_id', $event->id))->get();
        $sessionIds = $sessions->pluck('id');

        $recordHolderIds = DB::table('attendance_records')
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->whereIn('attendance_records.session_id', $sessionIds)
            ->when(! $live && $departmentId, fn ($q) => $q->whereRaw(SemesterScope::RECORD_DEPARTMENT.' = ?', [$departmentId]))
            ->distinct()
            ->pluck('attendance_records.student_id');

        if ($live) {
            // In the event's departments; active, or already holding a record here.
            $includedDepartmentIds = $event->includedDepartmentIds();
            $rosterIds = $people
                ->filter(fn (Student $p) => in_array($p->department_id, $includedDepartmentIds, true)
                    && ($p->status !== Student::STATUS_INACTIVE || $recordHolderIds->contains($p->id)))
                ->pluck('id');
        } else {
            $rosterIds = $recordHolderIds->values();
        }

        // For sessions ended without EndSession: active students -> Missed, staff -> Present.
        $activeIds = $live
            ? $people->filter(fn (Student $p) => $p->status !== Student::STATUS_INACTIVE)->pluck('id')
            : collect();
        $staffIds = $people->filter(fn (Student $p) => $p->role !== Role::Student)->pluck('id');

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
        // has a real attendance_records row — present, late, missed, or
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
        $unrecordedStaffPresentTotal = 0;

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

            if ($live && $session->status === SessionStatus::Ended) {
                $unrecorded = $rosterIds->intersect($activeIds)->diff($sessionExcludedIds)->diff($recordedIds);
                $staffCount = $unrecorded->intersect($staffIds)->count();
                $unrecordedStaffPresentTotal += $staffCount;
                $unrecordedAbsentTotal += $unrecorded->count() - $staffCount;
            }
        }

        return [
            'id' => $event->id,
            'name' => $event->name,
            'status' => $event->status->value,
            'present' => (int) ($statusCounts['present'] ?? 0) + $unrecordedStaffPresentTotal,
            'late' => (int) ($statusCounts['late'] ?? 0),
            'missed' => (int) ($statusCounts['missed'] ?? 0) + $unrecordedAbsentTotal,
            'excluded' => $excludedTotal,
        ];
    }
}
