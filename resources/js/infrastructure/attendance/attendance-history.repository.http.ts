import { httpClient } from '@/infrastructure/http/client';
import type { AttendanceStatus, CheckType, WindowType } from '@/domain/enums';

/**
 * One row in the admin attendance audit ledger (GET /attendance-history)
 * — "who scanned this, and when" for a single attendance record. Mirrors
 * PenaltyLedgerEntry's shape so the two admin ledgers read consistently;
 * see BuildAttendanceHistory on the backend for the query this maps.
 */
export interface AttendanceHistoryEntry {
    id: number;
    studentId: number;
    studentNumber: string;
    lastName: string;
    firstName: string;
    departmentId: number;
    departmentCode: string | null;
    /**
     * Ready-to-use URL for the attendee's photo, or null when none is on
     * file — the ledger renders the real person rather than a status
     * glyph, and UserAvatar falls back to initials when this is null.
     */
    photoUrl: string | null;
    major: string | null;
    yearLevel: string | null;
    section: string | null;
    status: AttendanceStatus;
    scannedAt: string | null;
    /** Null for an Absent/Excluded/Pending row — nobody scanned it. */
    scannedBy: number | null;
    scannedByName: string | null;
    scannedByRole: string | null;
    eventId: number;
    eventName: string;
    dayNumber: number;
    date: string;
    windowType: WindowType;
    checkType: CheckType;
}

export type AttendanceHistoryStatusFilter = 'all' | 'present' | 'late' | 'absent' | 'excluded';
export type AttendanceHistorySort = 'recent' | 'name' | 'course' | 'year_level' | 'section' | 'major' | 'event';

export interface AttendanceHistoryFilters {
    departmentId?: number;
    major?: string;
    yearLevel?: string;
    section?: string;
    /** Omitted = every event ("all or per event" per spec). */
    eventId?: number;
    status?: AttendanceHistoryStatusFilter;
    search?: string;
    sort?: AttendanceHistorySort;
    page?: number;
    perPage?: number;
}

export interface PaginatedAttendanceHistory {
    data: AttendanceHistoryEntry[];
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
}

/**
 * Distinct major/year-level/section values already on file, for the
 * ledger's free-text filters to autosuggest against — see
 * BuildAttendanceHistoryFilterOptions on the backend.
 */
export interface AttendanceHistoryFilterOptions {
    majors: string[];
    yearLevels: string[];
    sections: string[];
}

interface RawAttendanceHistoryEntry {
    id: number;
    student_id: number;
    student_number: string;
    last_name: string;
    first_name: string;
    department_id: number;
    department_code: string | null;
    photo_url: string | null;
    major: string | null;
    year_level: string | null;
    section: string | null;
    status: string;
    scanned_at: string | null;
    scanned_by: number | null;
    scanned_by_name: string | null;
    scanned_by_role: string | null;
    event_id: number;
    event_name: string;
    day_number: number;
    date: string;
    window_type: string;
    check_type: string;
}

interface RawPaginatedAttendanceHistory {
    data: RawAttendanceHistoryEntry[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
}

interface RawAttendanceHistoryFilterOptions {
    majors: string[];
    year_levels: string[];
    sections: string[];
}

function toAttendanceHistoryEntry(raw: RawAttendanceHistoryEntry): AttendanceHistoryEntry {
    return {
        id: raw.id,
        studentId: raw.student_id,
        studentNumber: raw.student_number,
        lastName: raw.last_name,
        firstName: raw.first_name,
        departmentId: raw.department_id,
        departmentCode: raw.department_code,
        photoUrl: raw.photo_url ?? null,
        major: raw.major,
        yearLevel: raw.year_level,
        section: raw.section,
        status: raw.status as AttendanceStatus,
        scannedAt: raw.scanned_at,
        scannedBy: raw.scanned_by,
        scannedByName: raw.scanned_by_name,
        scannedByRole: raw.scanned_by_role,
        eventId: raw.event_id,
        eventName: raw.event_name,
        dayNumber: raw.day_number,
        date: raw.date,
        windowType: raw.window_type as WindowType,
        checkType: raw.check_type as CheckType,
    };
}

export const httpAttendanceHistoryRepository = {
    // Admin audit ledger (System Admin / CSG Admin / SC Admin — see
    // AttendanceSessionPolicy::viewReport). An SC Admin's department_id
    // is forced server-side, same as the students list and penalty
    // ledger, so a mismatched department_id filter here is harmless.
    async list(filters: AttendanceHistoryFilters): Promise<PaginatedAttendanceHistory> {
        const { data } = await httpClient.get<RawPaginatedAttendanceHistory>('/attendance-history', {
            params: {
                department_id: filters.departmentId,
                major: filters.major,
                year_level: filters.yearLevel,
                section: filters.section,
                event_id: filters.eventId,
                status: filters.status,
                search: filters.search,
                sort: filters.sort,
                page: filters.page,
                per_page: filters.perPage,
            },
        });

        return {
            data: data.data.map(toAttendanceHistoryEntry),
            currentPage: data.current_page,
            lastPage: data.last_page,
            total: data.total,
            perPage: data.per_page,
        };
    },

    // Populates the Major/Year level/Section filter autosuggest — see
    // AttendanceHistoryFilterOptionsController. Same SC Admin department
    // scoping as `list`, enforced server-side.
    async filterOptions(): Promise<AttendanceHistoryFilterOptions> {
        const { data } = await httpClient.get<RawAttendanceHistoryFilterOptions>(
            '/attendance-history/filter-options',
        );

        return {
            majors: data.majors,
            yearLevels: data.year_levels,
            sections: data.sections,
        };
    },
};
