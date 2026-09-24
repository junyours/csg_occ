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
    absent: number;
    excluded: number;
    pending: number;
    totalStudents: number;
}

/**
 * Present/Late/Absent summed across every event and session ever run —
 * never null, unlike activeSessionCounts below (which is tied to whatever
 * session happens to be ongoing right now, and disappears the moment
 * nothing is — between windows, or once an event has ended).
 */
export interface DashboardOverallCounts {
    present: number;
    late: number;
    absent: number;
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
 * department's tracked (present + late + absent) as a share of every
 * department's tracked total combined.
 */
export interface DashboardDepartmentAttendance {
    departmentId: number;
    departmentName: string;
    departmentCode: string;
    present: number;
    late: number;
    absent: number;
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
    totalStudents: number;
    eventsCount: number;
    activeSession: DashboardActiveSession | null;
    activeSessionCounts: DashboardSessionCounts | null;
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
    totals: { present: number; late: number; absent: number; excluded: number };
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
    absent: number;
    excluded: number;
    pending: number;
    total_students: number;
}

interface RawOverallCounts {
    present: number;
    late: number;
    absent: number;
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
    present: number;
    late: number;
    absent: number;
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
    total_students?: number;
    events_count?: number;
    active_session: RawActiveSession | null;
    active_session_counts?: RawSessionCounts | null;
    overall_attendance_counts?: RawOverallCounts;
    attendance_by_department?: RawDepartmentAttendance[];
    penalty_total?: number;
    penalty_by_event?: RawEventPenalty[];
    penalty_by_department?: RawDepartmentPenalty[];
    my_scans?: number;
    total_scans?: number;
    contribution_percentage?: number;
    totals?: { present: number; late: number; absent: number; excluded: number };
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
        absent: raw.absent,
        excluded: raw.excluded,
        pending: raw.pending,
        totalStudents: raw.total_students,
    };
}

function toStreakLeaderboard(raw: RawStreakLeaderboardEntry[] | undefined): DashboardStreakLeaderboardEntry[] {
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
            totals: raw.totals ?? { present: 0, late: 0, absent: 0, excluded: 0 },
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
        totalStudents: raw.total_students ?? 0,
        eventsCount: raw.events_count ?? 0,
        activeSession,
        activeSessionCounts: toSessionCounts(raw.active_session_counts),
        overallAttendanceCounts: raw.overall_attendance_counts ?? { present: 0, late: 0, absent: 0 },
        attendanceByDepartment: (raw.attendance_by_department ?? []).map((row) => ({
            departmentId: row.department_id,
            departmentName: row.department_name,
            departmentCode: row.department_code,
            present: row.present,
            late: row.late,
            absent: row.absent,
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

export const httpDashboardRepository = {
    async summary(): Promise<DashboardSummary> {
        const { data } = await httpClient.get<RawDashboardSummary>('/dashboard');
        return toDashboardSummary(data);
    },
};
