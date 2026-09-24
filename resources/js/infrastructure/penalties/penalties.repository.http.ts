import { httpClient } from '@/infrastructure/http/client';
import type { CheckType, WindowType } from '@/domain/enums';

export interface MyPenaltyEntry {
    id: number;
    amount: number;
    reason: string;
    isReversed: boolean;
    /** Full name of the admin who reversed it, or null if never reversed. */
    reversedBy: string | null;
    createdAt: string;
    eventId: number;
    eventName: string;
    dayNumber: number;
    date: string;
    windowType: WindowType;
    checkType: CheckType;
}

export interface MyPenaltyHistory {
    /** Sum of every non-reversed amount — same rule as the dashboard's penaltyTotal. */
    total: number;
    entries: MyPenaltyEntry[];
}

interface RawPenaltyEntry {
    id: number;
    amount: number;
    reason: string;
    is_reversed: boolean;
    reversed_by: string | null;
    created_at: string;
    event_id: number;
    event_name: string;
    day_number: number;
    date: string;
    window_type: string;
    check_type: string;
}

interface RawPenaltyHistory {
    total: number;
    entries: RawPenaltyEntry[];
}

function toPenaltyEntry(raw: RawPenaltyEntry): MyPenaltyEntry {
    return {
        id: raw.id,
        amount: raw.amount,
        reason: raw.reason,
        isReversed: raw.is_reversed,
        reversedBy: raw.reversed_by,
        createdAt: raw.created_at,
        eventId: raw.event_id,
        eventName: raw.event_name,
        dayNumber: raw.day_number,
        date: raw.date,
        windowType: raw.window_type as WindowType,
        checkType: raw.check_type as CheckType,
    };
}

/**
 * One row in the admin penalty ledger (GET /penalties) — the CSG-Admin-
 * facing counterpart to MyPenaltyEntry above. Carries the student's own
 * identity (a self-service ledger doesn't need to, since it's always the
 * caller) plus everything needed to decide whether to reverse it.
 */
export interface PenaltyLedgerEntry {
    id: number;
    studentId: number;
    studentNumber: string;
    lastName: string;
    firstName: string;
    photoUrl: string | null;
    departmentId: number;
    departmentCode: string | null;
    amount: number;
    reason: string;
    isReversed: boolean;
    reversedBy: number | null;
    reversedByName: string | null;
    reversalReason: string | null;
    reversedAt: string | null;
    createdAt: string;
    eventId: number;
    eventName: string;
    dayNumber: number;
    date: string;
    windowType: WindowType;
    checkType: CheckType;
}

export interface PenaltyLedgerFilters {
    departmentId?: number;
    eventId?: number;
    status?: 'all' | 'active' | 'reversed';
    search?: string;
    page?: number;
    perPage?: number;
}

/**
 * Totals for whatever the ledger's current filters select — see
 * BuildPenaltySummary. `total` sums every matching row's amount
 * (reversed included when status is 'reversed'/'all'), so it always
 * matches what's listed below it rather than the dashboard's
 * always-excludes-reversed balance.
 */
export interface PenaltyLedgerSummary {
    total: number;
    absentCount: number;
    lateCount: number;
    count: number;
}

export interface PaginatedPenaltyLedger {
    data: PenaltyLedgerEntry[];
    currentPage: number;
    lastPage: number;
    total: number;
    perPage: number;
    summary: PenaltyLedgerSummary;
}

interface RawPenaltyLedgerEntry {
    id: number;
    student_id: number;
    student_number: string;
    last_name: string;
    first_name: string;
    photo_url: string | null;
    department_id: number;
    department_code: string | null;
    amount: number;
    reason: string;
    is_reversed: boolean;
    reversed_by: number | null;
    reversed_by_name: string | null;
    reversal_reason: string | null;
    reversed_at: string | null;
    created_at: string;
    event_id: number;
    event_name: string;
    day_number: number;
    date: string;
    window_type: string;
    check_type: string;
}

interface RawPenaltyLedgerSummary {
    total: number;
    absentCount: number;
    lateCount: number;
    count: number;
}

interface RawPaginatedPenaltyLedger {
    data: RawPenaltyLedgerEntry[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    summary: RawPenaltyLedgerSummary;
}

function toPenaltyLedgerEntry(raw: RawPenaltyLedgerEntry): PenaltyLedgerEntry {
    return {
        id: raw.id,
        studentId: raw.student_id,
        studentNumber: raw.student_number,
        lastName: raw.last_name,
        firstName: raw.first_name,
        photoUrl: raw.photo_url ?? null,
        departmentId: raw.department_id,
        departmentCode: raw.department_code,
        amount: raw.amount,
        reason: raw.reason,
        isReversed: raw.is_reversed,
        reversedBy: raw.reversed_by,
        reversedByName: raw.reversed_by_name,
        reversalReason: raw.reversal_reason,
        reversedAt: raw.reversed_at,
        createdAt: raw.created_at,
        eventId: raw.event_id,
        eventName: raw.event_name,
        dayNumber: raw.day_number,
        date: raw.date,
        windowType: raw.window_type as WindowType,
        checkType: raw.check_type as CheckType,
    };
}

export const httpPenaltiesRepository = {
    // Always the caller's own ledger (backend keys it off the
    // authenticated user, not a student id param) — see
    // MyPenaltyHistoryController.
    async myHistory(): Promise<MyPenaltyHistory> {
        const { data } = await httpClient.get<RawPenaltyHistory>('/my-penalty-history');
        return { total: data.total, entries: data.entries.map(toPenaltyEntry) };
    },

    // Admin ledger (CSG Admin / System Admin only — see
    // AttendancePenaltyPolicy::viewAny) behind the dedicated Penalties page.
    async list(filters: PenaltyLedgerFilters): Promise<PaginatedPenaltyLedger> {
        const { data } = await httpClient.get<RawPaginatedPenaltyLedger>('/penalties', {
            params: {
                department_id: filters.departmentId,
                event_id: filters.eventId,
                status: filters.status,
                search: filters.search,
                page: filters.page,
                per_page: filters.perPage,
            },
        });

        return {
            data: data.data.map(toPenaltyLedgerEntry),
            currentPage: data.current_page,
            lastPage: data.last_page,
            total: data.total,
            perPage: data.per_page,
            // Already camelCase on the wire (BuildPenaltySummary returns
            // it that way directly, unlike the snake_case ledger rows
            // above), so no field-by-field mapping needed here.
            summary: data.summary,
        };
    },

    // CSG Admin manually excuses a penalty after the fact (spec §7.3) —
    // see ReversePenalty on the backend. One-way: reversing an
    // already-reversed penalty is rejected server-side (409).
    //
    // The endpoint returns the bare AttendancePenalty model, not the
    // flattened ledger-row shape /penalties returns (it has no
    // event/session context) — nothing here needs that response body,
    // since useReversePenalty just invalidates the list query and lets
    // it refetch the row in the shape the page already knows how to
    // render, rather than trying to reshape two different payloads into one.
    async reverse(penaltyId: number, reason: string): Promise<void> {
        await httpClient.patch(`/penalties/${penaltyId}/reverse`, { reason });
    },
};
