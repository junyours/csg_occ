<?php

namespace App\Application\Actions\Dashboard;

use App\Application\Actions\Attendance\BuildStreakLeaderboard;
use App\Application\Actions\Attendance\ComputeAttendanceStreaks;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class BuildDashboardSummary
{
    public function __construct(
        private readonly ComputeAttendanceStreaks $computeAttendanceStreaks = new ComputeAttendanceStreaks,
        private readonly BuildStreakLeaderboard $buildStreakLeaderboard = new BuildStreakLeaderboard
    ) {
    }

    /**
     * Role-scoped dashboard summary. Every role gets a different shape —
     * dispatches on $user->role rather than returning one bloated payload
     * every caller has to pick fields out of. Always the authenticated
     * caller's own scope, same reasoning as EventMyAttendanceController:
     * nothing here can reveal another account's data, so no extra Gate
     * is needed beyond being logged in.
     *
     * Every variant also carries the same global top-5 streak
     * leaderboard (spec: "global leader board of students streak
     * visible in the Dashboard top 5") — it isn't scoped per role/
     * department the way the rest of an SC Admin's dashboard is,
     * because it's explicitly a school-wide ranking, not a
     * departmental one.
     */
    public function __invoke(Student $user): array
    {
        $streakLeaderboard = ($this->buildStreakLeaderboard)(5);

        return match ($user->role) {
            Role::SystemAdmin, Role::CsgAdmin => $this->buildAdminSummary($user, departmentId: null, streakLeaderboard: $streakLeaderboard),
            Role::ScAdmin => $this->buildAdminSummary($user, departmentId: $user->sc_admin_department_id, streakLeaderboard: $streakLeaderboard),
            Role::Officer => $this->buildOfficerSummary($user, $streakLeaderboard),
            Role::Student => $this->buildStudentSummary($user, $streakLeaderboard),
        };
    }

    /**
     * CSG/System Admin (global) and SC Admin (own department only, via
     * $departmentId) share the exact same shape — SC Admin is just the
     * same dashboard with every count filtered to one department.
     */
    private function buildAdminSummary(Student $user, ?int $departmentId, array $streakLeaderboard): array
    {
        $studentQuery = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId));

        $session = $this->findActiveSession();

        return [
            'role' => $user->role->value,
            'scope' => $departmentId ? 'department' : 'global',
            'department' => $departmentId ? Department::find($departmentId, ['id', 'name', 'code']) : null,
            'total_students' => $studentQuery->count(),
            'events_count' => EventModel::count(),
            'active_session' => $this->describeSession($session),
            'active_session_counts' => $session ? $this->sessionCounts($session, $departmentId) : null,
            // Present/Late/Absent summed across every event and session
            // that has ever run — never null, unlike active_session_counts
            // above. That block is tied to whatever session happens to be
            // `ongoing` right now, so it goes null the moment nothing is
            // (between windows, or once CSG has ended the event) — but the
            // admin still needs to see the attendance picture after an
            // event ends, not just while it's live.
            'overall_attendance_counts' => $this->overallAttendanceCounts($departmentId),
            // Same all-time counts as overall_attendance_counts above,
            // broken down per department — "which department is actually
            // showing up" is a different (and just as useful) question
            // from the single global tracked total.
            'attendance_by_department' => $this->attendanceByDepartment($departmentId),
            // Same "never disappears" reasoning as overall_attendance_counts
            // above — penalties keep mattering after an event ends (that's
            // the whole point of a penalty ledger), so these aren't tied
            // to any active session either.
            'penalty_total' => $this->penaltyTotal($departmentId),
            'penalty_by_event' => $this->penaltyByEvent($departmentId),
            // Same "grand total vs. per-slice" shape as attendance_by_department
            // above, but for penalties instead of attendance — "which
            // department is actually costing the most" is a different (and
            // just as useful) question from penalty-by-event.
            'penalty_by_department' => $this->penaltyByDepartment($departmentId),
            'streak_leaderboard' => $streakLeaderboard,
        ];
    }

    /**
     * Spec: "officer show count of all students it scan, total student
     * scans (sum all officers), and percentage that officer contributes
     * to the total". All-time totals via AttendanceRecord.scanned_by —
     * counted once per record since ScanAttendance is idempotent (a
     * duplicate scan never creates a second row).
     */
    private function buildOfficerSummary(Student $user, array $streakLeaderboard): array
    {
        $myScans = AttendanceRecord::where('scanned_by', $user->id)->count();
        $totalScans = AttendanceRecord::whereNotNull('scanned_by')->count();

        return [
            'role' => Role::Officer->value,
            'active_session' => $this->describeSession($this->findActiveSession()),
            'my_scans' => $myScans,
            'total_scans' => $totalScans,
            'contribution_percentage' => $totalScans > 0
                ? round($myScans / $totalScans * 100, 2)
                : 0.0,
            'streak_leaderboard' => $streakLeaderboard,
        ];
    }

    private function buildStudentSummary(Student $user, array $streakLeaderboard): array
    {
        $statusCounts = AttendanceRecord::where('student_id', $user->id)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $penaltyTotal = (float) AttendancePenalty::where('student_id', $user->id)
            ->where('is_reversed', false)
            ->sum('amount');

        $session = $this->findActiveSession();

        // This caller's own row out of the same global, cross-event
        // reduction the leaderboard above is built from — see
        // ComputeAttendanceStreaks. Never scoped to whatever event
        // happens to be active right now: it's the running streak across
        // every event this student has ever been eligible for, carrying
        // straight through event boundaries.
        $streak = ($this->computeAttendanceStreaks)($user->id)->get($user->id)
            ?? ['current' => 0, 'longest' => 0, 'latest_scan_at' => null];

        return [
            'role' => Role::Student->value,
            'totals' => [
                'present' => (int) ($statusCounts['present'] ?? 0),
                'late' => (int) ($statusCounts['late'] ?? 0),
                'absent' => (int) ($statusCounts['absent'] ?? 0),
                'excluded' => (int) ($statusCounts['excluded'] ?? 0),
            ],
            'penalty_total' => $penaltyTotal,
            'active_session' => $this->describeSession($session),
            'active_session_status' => $session ? $this->myStatusForSession($session, $user) : null,
            'streak' => [
                'current' => $streak['current'],
                'longest' => $streak['longest'],
            ],
            'streak_leaderboard' => $streakLeaderboard,
        ];
    }

    /**
     * "Active" = a session currently ongoing right now. Spec models each
     * window's time-in/time-out as separate session rows (CheckType), so
     * in theory more than one could be ongoing at once — this surfaces
     * the most recently started one, which is what a dashboard glance
     * actually needs.
     */
    private function findActiveSession(): ?AttendanceSession
    {
        return AttendanceSession::with('eventDay.event')
            ->where('status', SessionStatus::Ongoing)
            ->orderByDesc('id')
            ->first();
    }

    private function describeSession(?AttendanceSession $session): ?array
    {
        if (! $session) {
            return null;
        }

        return [
            'session_id' => $session->id,
            'window_type' => $session->window_type->value,
            'check_type' => $session->check_type->value,
            'start_time' => $session->start_time,
            'end_time' => $session->end_time,
            'event_id' => $session->eventDay->event_id,
            'event_name' => $session->eventDay->event->name,
            'event_day' => [
                'date' => $session->eventDay->date->format('Y-m-d'),
                'day_number' => $session->eventDay->day_number,
            ],
        ];
    }

    /**
     * Present/Late come straight from recorded attendance_records; Absent
     * only ever appears here as non-zero once EndSession has actually run
     * (it's the only thing allowed to assign it) — same "never guess
     * Absent" rule as BuildSessionReport.
     */
    private function sessionCounts(AttendanceSession $session, ?int $departmentId): array
    {
        // Same department-scope rule as everywhere else (see
        // EventModel::includedDepartmentIds) — a department this event
        // never included shouldn't inflate the "pending" count here.
        $includedDepartmentIds = $session->eventDay->event->includedDepartmentIds();

        $rosterIds = Student::where('role', Role::Student)
            ->whereIn('department_id', $includedDepartmentIds)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->pluck('id');

        $recordCounts = AttendanceRecord::where('session_id', $session->id)
            ->whereIn('student_id', $rosterIds)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $excludedCount = collect(Exclusion::excludedStudentIdsForSession($session))
            ->intersect($rosterIds)
            ->count();

        $present = (int) ($recordCounts['present'] ?? 0);
        $late = (int) ($recordCounts['late'] ?? 0);
        $absent = (int) ($recordCounts['absent'] ?? 0);
        $pending = $rosterIds->count() - $present - $late - $absent - $excludedCount;

        return [
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'excluded' => $excludedCount,
            'pending' => max($pending, 0),
            'total_students' => $rosterIds->count(),
        ];
    }

    /**
     * Present/Late/Absent totals across every AttendanceRecord ever
     * written, department-scoped the same way as everything else in this
     * summary — this is the all-time counterpart to sessionCounts(),
     * which only ever looks at one (currently ongoing) session. Unlike
     * that method, this one is never null and never goes away: it isn't
     * gated on any session or event being active, so an admin still sees
     * it after every event has ended.
     *
     * Excluded students never get an AttendanceRecord row at all (see
     * EndSession::markMissingRecords), so there's no all-time "excluded"
     * count to sum here the way sessionCounts() can for one session —
     * that would mean re-deriving exclusion scope for every session ever
     * run, which isn't worth it for a dashboard tile.
     */
    private function overallAttendanceCounts(?int $departmentId): array
    {
        $rosterIds = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->pluck('id');

        $recordCounts = AttendanceRecord::whereIn('student_id', $rosterIds)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return [
            'present' => (int) ($recordCounts['present'] ?? 0),
            'late' => (int) ($recordCounts['late'] ?? 0),
            'absent' => (int) ($recordCounts['absent'] ?? 0),
        ];
    }

    /**
     * The same all-time Present/Late/Absent counts as
     * overallAttendanceCounts(), broken down per department, each row
     * carrying its share of the overall tracked total (present + late +
     * absent, summed across every department returned here) — the
     * attendance counterpart to penaltyByEvent() below, same "grand
     * total vs. per-slice" reasoning. For an SC Admin ($departmentId
     * set) this naturally collapses to their one department at 100%,
     * same as every other scoped total in this class.
     *
     * @return array<int, array{department_id: int, department_name: string, department_code: string, present: int, late: int, absent: int, tracked: int, percentage_of_overall: float}>
     */
    private function attendanceByDepartment(?int $departmentId): array
    {
        $rows = AttendanceRecord::query()
            ->join('students', 'students.id', '=', 'attendance_records.student_id')
            ->join('departments', 'departments.id', '=', 'students.department_id')
            ->where('students.role', Role::Student->value)
            ->when($departmentId, fn ($q) => $q->where('students.department_id', $departmentId))
            ->select(
                'departments.id as department_id',
                'departments.name as department_name',
                'departments.code as department_code',
                DB::raw("sum(case when attendance_records.status = 'present' then 1 else 0 end) as present"),
                DB::raw("sum(case when attendance_records.status = 'late' then 1 else 0 end) as late"),
                DB::raw("sum(case when attendance_records.status = 'absent' then 1 else 0 end) as absent"),
            )
            ->groupBy('departments.id', 'departments.name', 'departments.code')
            ->get();

        $overallTracked = $rows->sum(fn ($row) => (int) $row->present + (int) $row->late + (int) $row->absent);

        return $rows
            ->map(function ($row) use ($overallTracked) {
                $tracked = (int) $row->present + (int) $row->late + (int) $row->absent;

                return [
                    'department_id' => (int) $row->department_id,
                    'department_name' => $row->department_name,
                    'department_code' => $row->department_code,
                    'present' => (int) $row->present,
                    'late' => (int) $row->late,
                    'absent' => (int) $row->absent,
                    'tracked' => $tracked,
                    'percentage_of_overall' => $overallTracked > 0 ? round($tracked / $overallTracked * 100, 2) : 0.0,
                ];
            })
            ->sortByDesc('tracked')
            ->values()
            ->all();
    }

    /**
     * All-time penalty total across every event, department-scoped the
     * same way as everything else here. Reversed penalties (`is_reversed`)
     * are excluded — same rule the student-facing total already uses
     * (spec §7.3: the ledger is "auditable and reversible", a reversed
     * entry shouldn't still count against anyone's balance).
     */
    private function penaltyTotal(?int $departmentId): float
    {
        $rosterIds = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->pluck('id');

        return (float) AttendancePenalty::whereIn('student_id', $rosterIds)
            ->where('is_reversed', false)
            ->sum('amount');
    }

    /**
     * The same all-time total as penaltyTotal(), broken down per event —
     * "how much did Intramurals 2026 cost the roster, in total" is a
     * different (and just as useful) question from the single grand
     * total. Joined straight through session → event_day → event rather
     * than loading every penalty into PHP and grouping in memory, since
     * this can span every event/session ever run.
     *
     * @return array<int, array{event_id: int, event_name: string, penalty_total: float, percentage_of_overall: float}>
     */
    private function penaltyByEvent(?int $departmentId): array
    {
        $rosterIds = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->pluck('id');

        $rows = AttendancePenalty::query()
            ->join('attendance_sessions', 'attendance_sessions.id', '=', 'attendance_penalties.session_id')
            ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
            ->join('events', 'events.id', '=', 'event_days.event_id')
            ->whereIn('attendance_penalties.student_id', $rosterIds)
            ->where('attendance_penalties.is_reversed', false)
            ->select(
                'events.id as event_id',
                'events.name as event_name',
                DB::raw('sum(attendance_penalties.amount) as total'),
            )
            ->groupBy('events.id', 'events.name')
            ->orderByDesc('total')
            ->get();

        // Same "each row's share of the grand total" shape as
        // attendanceByDepartment() above — the grand total here is the
        // sum of exactly the rows returned, not penaltyTotal() again, so
        // the percentages in this list always foot to 100 on their own.
        $overallTotal = (float) $rows->sum('total');

        return $rows
            ->map(fn ($row) => [
                'event_id' => (int) $row->event_id,
                'event_name' => $row->event_name,
                'penalty_total' => (float) $row->total,
                'percentage_of_overall' => $overallTotal > 0 ? round((float) $row->total / $overallTotal * 100, 2) : 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * The same all-time penalty total as penaltyTotal(), broken down per
     * department — the penalty counterpart to attendanceByDepartment()
     * above. percentage_of_overall is this department's share of the sum
     * of every row returned here (always foots to 100 on its own), same
     * "grand total is the sum of the rows, not penaltyTotal() again" rule
     * as penaltyByEvent(). For an SC Admin ($departmentId set) this
     * naturally collapses to their one department at 100%.
     *
     * @return array<int, array{department_id: int, department_name: string, department_code: string, penalty_total: float, percentage_of_overall: float}>
     */
    private function penaltyByDepartment(?int $departmentId): array
    {
        $rows = AttendancePenalty::query()
            ->join('students', 'students.id', '=', 'attendance_penalties.student_id')
            ->join('departments', 'departments.id', '=', 'students.department_id')
            ->where('students.role', Role::Student->value)
            ->where('attendance_penalties.is_reversed', false)
            ->when($departmentId, fn ($q) => $q->where('students.department_id', $departmentId))
            ->select(
                'departments.id as department_id',
                'departments.name as department_name',
                'departments.code as department_code',
                DB::raw('sum(attendance_penalties.amount) as total'),
            )
            ->groupBy('departments.id', 'departments.name', 'departments.code')
            ->orderByDesc('total')
            ->get();

        $overallTotal = (float) $rows->sum('total');

        return $rows
            ->map(fn ($row) => [
                'department_id' => (int) $row->department_id,
                'department_name' => $row->department_name,
                'department_code' => $row->department_code,
                'penalty_total' => (float) $row->total,
                'percentage_of_overall' => $overallTotal > 0 ? round((float) $row->total / $overallTotal * 100, 2) : 0.0,
            ])
            ->values()
            ->all();
    }

    private function myStatusForSession(AttendanceSession $session, Student $user): ?string
    {
        if (in_array($user->id, Exclusion::excludedStudentIdsForSession($session), true)) {
            return 'excluded';
        }

        $record = AttendanceRecord::where('session_id', $session->id)
            ->where('student_id', $user->id)
            ->first();

        return $record?->status->value;
    }
}
