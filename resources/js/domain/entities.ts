import type { AttendanceStatus, CheckType, EventStatus, ExclusionScope, ExclusionStatus, Role, SessionStatus, WindowType } from '@/domain/enums';

export interface Department {
    id: number;
    name: string;
    code: string;
    logoPath: string | null;
    logoUrl: string | null;
}

export interface AcademicYear {
    id: number;
    name: string;
    startDate: string | null;
    endDate: string | null;
    isActive: boolean;
    createdBy: number;
}

/** A term within an AcademicYear (e.g. "1st Semester") — a real, dated,
 *  admin-managed record, not a fixed enum tag. Every event belongs to one. */
export interface Semester {
    id: number;
    academicYearId: number;
    name: string;
    startDate: string | null;
    endDate: string | null;
    isActive: boolean;
    createdBy: number;
}

export interface Student {
    id: number;
    studentNumber: string;
    lastName: string;
    firstName: string;
    middleName: string | null;
    suffix: string | null;
    departmentId: number;
    // Denormalized from the API's eager-loaded `department` relation —
    // undefined where a caller didn't request/need it (e.g. the bulk
    // roster list, which only needs department_id for filtering), null
    // never actually occurs once loaded since every student belongs to
    // a department. Mirrors EventEntity's semesterTerm/academicYearName
    // convention.
    departmentName?: string;
    departmentCode?: string;
    // Only some courses have a sub-major (BSBA's FM/MM, BSED's ENG, ...) —
    // null for courses that don't (BEED, BSIT).
    major: string | null;
    // Backend column is a free-text string (StoreStudentRequest: 'string',
    // 'max:20' — e.g. "1", "2", "Irregular"), not a number.
    yearLevel: string | null;
    section: string | null;
    // ISO date string (YYYY-MM-DD), or null if not recorded.
    dateEnrolled: string | null;
    role: Role;
    scAdminDepartmentId: number | null;
    officerEventId: number | null;
    semesterId: number | null;
    photoPath: string | null;
    photoUrl: string | null;
    mustChangePassword: boolean;
    hasAcceptedTerms: boolean;
}

export interface EventEntity {
    id: number;
    name: string;
    description: string | null;
    createdBy: number;
    semesterId: number | null;
    /**
     * The event's own lifecycle — ongoing until a CSG Admin explicitly
     * ends it. Independent of any individual session's status: a session
     * ending (or every session having ended) does NOT mean the event is
     * over — see EndEvent on the backend.
     */
    status: EventStatus;
    /**
     * Denormalized display fields from the API's eager-loaded
     * `semester.academicYear` relation — undefined where the caller
     * didn't request/need them, null where the event genuinely has no
     * semester. `semesterTerm` is the raw Semester enum value
     * (e.g. "semester_1"); look it up against SEMESTER_LABEL to display.
     */
    semesterTerm?: string | null;
    academicYearName?: string | null;
    /**
     * The departments this event is scoped to — a student outside this
     * list is never tracked for attendance here (see backend
     * EventModel::includesDepartment). Every department currently in the
     * system by default; narrowed at creation to e.g. "BSIT only".
     */
    departments: EventDepartment[];
}

export interface EventDepartment {
    id: number;
    name: string;
    code: string;
}

export interface EventDay {
    id: number;
    eventId: number;
    date: string;
    dayNumber: number;
}

export interface AttendanceSession {
    id: number;
    eventDayId: number;
    windowType: WindowType;
    checkType: CheckType;
    startTime: string;
    endTime: string;
    graceMinutes: number;
    penaltyLateAmount: number;
    penaltyAbsentAmount: number;
    status: SessionStatus;
}

/** An event day together with the sessions scheduled on it. */
export interface EventDayWithSessions extends EventDay {
    sessions: AttendanceSession[];
}

/** An event together with its full day/session tree, as returned by GET /events. */
export interface EventWithDays extends EventEntity {
    days: EventDayWithSessions[];
}

export interface AttendanceRecord {
    id: number;
    sessionId: number;
    studentId: number;
    scannedAt: string | null;
    status: AttendanceStatus;
    scannedBy: number | null;
}

export interface AttendancePenalty {
    id: number;
    studentId: number;
    sessionId: number;
    amount: number;
    reason: string;
    createdAt: string;
}

export interface Exclusion {
    id: number;
    studentId: number;
    eventId: number;
    scope: ExclusionScope;
    eventDayId: number | null;
    windowType: WindowType | null;
    reason: string;
    status: ExclusionStatus;
    batchId: string | null;
    createdBy: number;
    removedBy: number | null;
    removedAt: string | null;
}

export interface AuthenticatedUser {
    student: Student;
    token: string;
}
