import { httpClient } from '@/infrastructure/http/client';
import type { AttendanceStatus, CheckType, Role, WindowType } from '@/domain/enums';

export interface DashboardActiveSession {
    sessionId: number;
    windowType: WindowType;
    checkType: CheckType;
    startTime: string;
    endTime: string;
    eventId: number;
    eventName: string;
    eventDay: { date: string; dayNumber: number };
}

export interface DashboardSessionCounts {
    present: number;
    late: number;
    missed: number;
    excluded: number;
    /** Staff Present written automatically at session end (no scan). Already inside `present`. */
    auto: number;
    pending: number;
    totalStudents: number;
}

/** Counts for one already-ended session, keyed by the session they belong to. */
export interface DashboardEndedSessionCounts extends DashboardSessionCounts {
    sessionId: number;
}

/**
 * Present/Late/Missed summed across every event and session ever run —
 * never null, unlike activeSessionCounts below (which is tied to whatever
 * session happens to be ongoing right now, and disappears the moment
 * nothing is — between windows, or once an event has ended).
 */
export interface DashboardOverallCounts {
    present: number;
    late: number;
    missed: number;
}

/**
 * One event's slice of the all-time penalty total — see
 * AdminDashboardSummary.penaltyByEvent. percentageOfOverall is this row's
 * share of the sum of every row in the list (always foots to 100 on its
 * own), not a share of penaltyTotal above (which can include events this
 * list doesn't, in theory).
 */
export interface DashboardEventPenalty {
    eventId: number;
    eventName: string;
    penaltyTotal: number;
    percentageOfOverall: number;
}

/**
 * One department's slice of overallAttendanceCounts — the attendance
 * counterpart to DashboardEventPenalty above. percentageOfOverall is this
 * department's tracked (present + late + missed) as a share of every
 * department's tracked total combined.
 */
export interface DashboardDepartmentAttendance {
    departmentId: number;
    departmentName: string;
    departmentCode: string;
    /** Uploaded department logo, if any — shown instead of the initials. */
    departmentLogoUrl: string | null;
    present: number;
    late: number;
    missed: number;
    tracked: number;
    percentageOfOverall: number;
}

/**
 * One department's slice of penaltyTotal — the penalty counterpart to
 * DashboardDepartmentAttendance above. percentageOfOverall is this
 * department's share of the sum of every row in this list (always foots
 * to 100 on its own), not a share of penaltyTotal (which can include
 * departments this list doesn't, in theory).
 */
export interface DashboardDepartmentPenalty {
    departmentId: number;
    departmentName: string;
    departmentCode: string;
    penaltyTotal: number;
    percentageOfOverall: number;
}

/**
 * One row of the global, cross-department Top-5 streak leaderboard —
 * every role's dashboard carries the same list, since it's a school-wide
 * ranking rather than something scoped to whoever's looking at it. See
 * the backend's ComputeAttendanceStreaks/BuildStreakLeaderboard for how
 * rank and the tiebreak (fastest latest scan on an equal streak) are
 * derived.
 */
export interface DashboardStreakLeaderboardEntry {
    rank: number;
    studentId: number;
    studentName: string;
    section: string | null;
    departmentCode: string | null;
    photoUrl: string | null;
    currentStreak: number;
    longestStreak: number;
    /** ISO instant of this student's most recent real scan — null if they've never scanned. */
    latestScanAt: string | null;
}

/** Shared by System Admin / CSG Admin (scope 'global') and SC Admin (scope 'department'). */
export interface AdminDashboardSummary {
    role: Role;
    scope: 'global' | 'department';
    department: { id: number; name: string; code: string } | null;
    /** The semester the numbers cover; null when viewing "All semesters". */
    semester: { id: number; name: string; isCurrent: boolean } | null;
    allSemesters: boolean;
    /** Only when allSemesters — each semester listed separately, never blended. */
    bySemester: Array<{
        semesterId: number;
        semesterName: string;
        academicYearName: string | null;
        isCurrent: boolean;
        present: number;
        late: number;
        missed: number;
        penaltyTotal: number;
    }>;
    totalStudents: number;
    eventsCount: number;
    activeSession: DashboardActiveSession | null;
    activeSessionCounts: DashboardSessionCounts | null;
    /** Every ended session of a still-open event, for the schedule's "View details" summary. */
    endedSessionCounts: DashboardEndedSessionCounts[];
    overallAttendanceCounts: DashboardOverallCounts;
    /** The same all-time counts, broken down per department, highest first. */
    attendanceByDepartment: DashboardDepartmentAttendance[];
    /** All-time penalty total across every event (reversed penalties excluded). */
    penaltyTotal: number;
    /** The same total, broken down per event, highest first. */
    penaltyByEvent: DashboardEventPenalty[];
    /** The same total, broken down per department, highest first. */
    penaltyByDepartment: DashboardDepartmentPenalty[];
    /** Global top-5 attendance-streak leaderboard — see DashboardStreakLeaderboardEntry. */
    streakLeaderboard: DashboardStreakLeaderboardEntry[];
}

export interface OfficerDashboardSummary {
    role: Role;
    activeSession: DashboardActiveSession | null;
    myScans: number;
    totalScans: number;
    contributionPercentage: number;
    streakLeaderboard: DashboardStreakLeaderboardEntry[];
}

export interface StudentDashboardSummary {
    role: Role;
    totals: { present: number; late: number; missed: number; excluded: number };
    penaltyTotal: number;
    activeSession: DashboardActiveSession | null;
    activeSessionStatus: AttendanceStatus | null;
    /**
     * This caller's own current/longest attendance streak — global and
     * cross-event (spec: it keeps running across an event boundary
     * instead of resetting), computed server-side so it can never drift
     * from what the leaderboard below is ranking on.
     */
    streak: { current: number; longest: number };
    streakLeaderboard: DashboardStreakLeaderboardEntry[];
}

export type DashboardSummary = AdminDashboardSummary | OfficerDashboardSummary | StudentDashboardSummary;

interface RawActiveSession {
    session_id: number;
    window_type: string;
    check_type: string;
    start_time: string;
    end_time: string;
    event_id: number;
    event_name: string;
    event_day: { date: string; day_number: number };
}

interface RawSessionCounts {
    present: number;
    late: number;
    missed: number;
    excluded: number;
    auto?: number;
    pending: number;
    total_students: number;
}

interface RawEndedSessionCounts extends RawSessionCounts {
    session_id: number;
}

interface RawOverallCounts {
    present: number;
    late: number;
    missed: number;
}

interface RawEventPenalty {
    event_id: number;
    event_name: string;
    penalty_total: number;
    percentage_of_overall: number;
}

interface RawDepartmentAttendance {
    department_id: number;
    department_name: string;
    department_code: string;
    department_logo_url?: string | null;
    present: number;
    late: number;
    missed: number;
    tracked: number;
    percentage_of_overall: number;
}

interface RawDepartmentPenalty {
    department_id: number;
    department_name: string;
    department_code: string;
    penalty_total: number;
    percentage_of_overall: number;
}

interface RawStreakLeaderboardEntry {
    rank: number;
    student_id: number;
    student_name: string;
    section: string | null;
    department_code: string | null;
    photo_url: string | null;
    current_streak: number;
    longest_streak: number;
    latest_scan_at: string | null;
}

interface RawDashboardSummary {
    role: string;
    scope?: 'global' | 'department';
    department?: { id: number; name: string; code: string } | null;
    semester?: { id: number; name: string; is_current: boolean } | null;
    all_semesters?: boolean;
    by_semester?: Array<{
        semester_id: number;
        semester_name: string;
        academic_year_name: string | null;
        is_current: boolean;
        present: number;
        late: number;
        missed: number;
        penalty_total: number;
    }>;
    total_students?: number;
    events_count?: number;
    active_session: RawActiveSession | null;
    active_session_counts?: RawSessionCounts | null;
    ended_session_counts?: RawEndedSessionCounts[];
    overall_attendance_counts?: RawOverallCounts;
    attendance_by_department?: RawDepartmentAttendance[];
    penalty_total?: number;
    penalty_by_event?: RawEventPenalty[];
    penalty_by_department?: RawDepartmentPenalty[];
    my_scans?: number;
    total_scans?: number;
    contribution_percentage?: number;
    totals?: { present: number; late: number; missed: number; excluded: number };
    active_session_status?: string | null;
    streak?: { current: number; longest: number };
    streak_leaderboard?: RawStreakLeaderboardEntry[];
}

function toActiveSession(raw: RawActiveSession | null): DashboardActiveSession | null {
    if (!raw) return null;

    return {
        sessionId: raw.session_id,
        windowType: raw.window_type as WindowType,
        checkType: raw.check_type as CheckType,
        startTime: raw.start_time,
        endTime: raw.end_time,
        eventId: raw.event_id,
        eventName: raw.event_name,
        eventDay: { date: raw.event_day.date, dayNumber: raw.event_day.day_number },
    };
}

function toSessionCounts(raw: RawSessionCounts | null | undefined): DashboardSessionCounts | null {
    if (!raw) return null;

    return {
        present: raw.present,
        late: raw.late,
        missed: raw.missed,
        excluded: raw.excluded,
        auto: raw.auto ?? 0,
        pending: raw.pending,
        totalStudents: raw.total_students,
    };
}

export function toStreakLeaderboard(raw: RawStreakLeaderboardEntry[] | undefined): DashboardStreakLeaderboardEntry[] {
    return (raw ?? []).map((row) => ({
        rank: row.rank,
        studentId: row.student_id,
        studentName: row.student_name,
        section: row.section,
        departmentCode: row.department_code,
        photoUrl: row.photo_url,
        currentStreak: row.current_streak,
        longestStreak: row.longest_streak,
        latestScanAt: row.latest_scan_at,
    }));
}

function toDashboardSummary(raw: RawDashboardSummary): DashboardSummary {
    const activeSession = toActiveSession(raw.active_session);
    const streakLeaderboard = toStreakLeaderboard(raw.streak_leaderboard);

    if (raw.role === 'officer') {
        return {
            role: raw.role as Role,
            activeSession,
            myScans: raw.my_scans ?? 0,
            totalScans: raw.total_scans ?? 0,
            contributionPercentage: raw.contribution_percentage ?? 0,
            streakLeaderboard,
        };
    }

    if (raw.role === 'student') {
        return {
            role: raw.role as Role,
            totals: raw.totals ?? { present: 0, late: 0, missed: 0, excluded: 0 },
            penaltyTotal: raw.penalty_total ?? 0,
            activeSession,
            activeSessionStatus: (raw.active_session_status as AttendanceStatus | null) ?? null,
            streak: raw.streak ?? { current: 0, longest: 0 },
            streakLeaderboard,
        };
    }

    // system_admin / csg_admin / sc_admin
    return {
        role: raw.role as Role,
        scope: raw.scope ?? 'global',
        department: raw.department ?? null,
        semester: raw.semester ? { id: raw.semester.id, name: raw.semester.name, isCurrent: raw.semester.is_current } : null,
        allSemesters: raw.all_semesters ?? false,
        bySemester: (raw.by_semester ?? []).map((row) => ({
            semesterId: row.semester_id,
            semesterName: row.semester_name,
            academicYearName: row.academic_year_name,
            isCurrent: row.is_current,
            present: row.present,
            late: row.late,
            missed: row.missed,
            penaltyTotal: row.penalty_total,
        })),
        totalStudents: raw.total_students ?? 0,
        eventsCount: raw.events_count ?? 0,
        activeSession,
        activeSessionCounts: toSessionCounts(raw.active_session_counts),
        endedSessionCounts: (raw.ended_session_counts ?? []).map((row) => ({
            ...toSessionCounts(row)!,
            sessionId: row.session_id,
        })),
        overallAttendanceCounts: raw.overall_attendance_counts ?? { present: 0, late: 0, missed: 0 },
        attendanceByDepartment: (raw.attendance_by_department ?? []).map((row) => ({
            departmentId: row.department_id,
            departmentName: row.department_name,
            departmentCode: row.department_code,
            departmentLogoUrl: row.department_logo_url ?? null,
            present: row.present,
            late: row.late,
            missed: row.missed,
            tracked: row.tracked,
            percentageOfOverall: row.percentage_of_overall,
        })),
        penaltyTotal: raw.penalty_total ?? 0,
        penaltyByEvent: (raw.penalty_by_event ?? []).map((row) => ({
            eventId: row.event_id,
            eventName: row.event_name,
            penaltyTotal: row.penalty_total,
            percentageOfOverall: row.percentage_of_overall,
        })),
        penaltyByDepartment: (raw.penalty_by_department ?? []).map((row) => ({
            departmentId: row.department_id,
            departmentName: row.department_name,
            departmentCode: row.department_code,
            penaltyTotal: row.penalty_total,
            percentageOfOverall: row.percentage_of_overall,
        })),
        streakLeaderboard,
    };
}

/**
 * Present / Late / Missed / Excluded as plain counts for one event (and
 * optionally one day of it) — see the backend's BuildAttendanceBreakdown.
 * `tracked` is present + late + missed; `excluded` sits beside it, not
 * inside it. percentageOfOverall is this department's share of `tracked`.
 */
export interface AttendanceCounts {
    present: number;
    late: number;
    missed: number;
    excluded: number;
}

export interface DepartmentAttendanceBreakdown extends AttendanceCounts {
    departmentId: number;
    departmentName: string;
    departmentCode: string;
    departmentLogoUrl: string | null;
    tracked: number;
    percentageOfOverall: number;
}

export interface AttendanceBreakdown {
    overall: AttendanceCounts;
    byDepartment: DepartmentAttendanceBreakdown[];
}

export interface AttendanceBreakdownParams {
    /** null/omitted = every event in the semester scope. */
    eventId?: number | null;
    /** Only meaningful together with eventId. null/omitted = every day. */
    eventDayId?: number | null;
    /** Only used when no event is given. */
    semester?: number | 'all' | null;
}

interface RawAttendanceBreakdown {
    overall: AttendanceCounts;
    by_department: Array<{
        department_id: number;
        department_name: string;
        department_code: string;
        department_logo_url: string | null;
        present: number;
        late: number;
        missed: number;
        excluded: number;
        tracked: number;
        percentage_of_overall: number;
    }>;
}

export const httpDashboardRepository = {
    async attendanceBreakdown({ eventId, eventDayId, semester }: AttendanceBreakdownParams): Promise<AttendanceBreakdown> {
        const { data } = await httpClient.get<RawAttendanceBreakdown>('/dashboard/attendance-breakdown', {
            params: {
                ...(eventId ? { event_id: eventId } : {}),
                ...(eventId && eventDayId ? { event_day_id: eventDayId } : {}),
                ...(!eventId && semester === 'all' ? { scope: 'all' } : {}),
                ...(!eventId && typeof semester === 'number' ? { semester_id: semester } : {}),
            },
        });

        return {
            overall: data.overall,
            byDepartment: data.by_department.map((row) => ({
                departmentId: row.department_id,
                departmentName: row.department_name,
                departmentCode: row.department_code,
                departmentLogoUrl: row.department_logo_url,
                present: row.present,
                late: row.late,
                missed: row.missed,
                excluded: row.excluded,
                tracked: row.tracked,
                percentageOfOverall: row.percentage_of_overall,
            })),
        };
    },

    /** `semester`: omitted/null = current; a number = that semester; 'all' = every semester (admins only). */
    async summary(semester?: number | 'all' | null): Promise<DashboardSummary> {
        const { data } = await httpClient.get<RawDashboardSummary>('/dashboard', {
            params: semester === 'all' ? { scope: 'all' } : semester ? { semester_id: semester } : undefined,
        });
        return toDashboardSummary(data);
    },
};
