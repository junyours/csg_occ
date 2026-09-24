import { httpClient } from '@/infrastructure/http/client';
import type { ExclusionScope, ExclusionStatus, WindowType } from '@/domain/enums';

/**
 * One row on the Manage Exclusions screen (student-exclusion-feature-
 * plan.md §4B) — as returned by GET /events/{event}/exclusions (see
 * backend ListExclusionsForEvent). Deliberately flat, not pre-grouped:
 * the page groups by scope/day/window itself for display.
 */
export interface ExclusionListItem {
    id: number;
    scope: ExclusionScope;
    status: ExclusionStatus;
    reason: string;
    eventDayId: number | null;
    dayNumber: number | null;
    windowType: WindowType | null;
    studentId: number;
    studentNumber: string;
    studentName: string;
    createdBy: string;
    createdAt: string;
    removedBy: string | null;
    removedAt: string | null;
    batchId: string | null;
    /**
     * Whether this row's own target scope has already ended — the
     * frontend greys out "Remove" using this rather than re-deriving
     * the rule client-side (§6: an exclusion can only be removed while
     * its own scope hasn't ended yet).
     */
    targetHasEnded: boolean;
}

interface RawExclusionListItem {
    id: number;
    scope: string;
    status: string;
    reason: string;
    event_day_id: number | null;
    day_number: number | null;
    window_type: string | null;
    student_id: number;
    student_number: string;
    student_name: string;
    created_by: string;
    created_at: string;
    removed_by: string | null;
    removed_at: string | null;
    batch_id: string | null;
    target_has_ended: boolean;
}

function toExclusionListItem(raw: RawExclusionListItem): ExclusionListItem {
    return {
        id: raw.id,
        scope: raw.scope as ExclusionScope,
        status: raw.status as ExclusionStatus,
        reason: raw.reason,
        eventDayId: raw.event_day_id,
        dayNumber: raw.day_number,
        windowType: raw.window_type as WindowType | null,
        studentId: raw.student_id,
        studentNumber: raw.student_number,
        studentName: raw.student_name,
        createdBy: raw.created_by,
        createdAt: raw.created_at,
        removedBy: raw.removed_by,
        removedAt: raw.removed_at,
        batchId: raw.batch_id,
        targetHasEnded: raw.target_has_ended,
    };
}

/**
 * Payload for a single manual exclusion (student-exclusion-feature-
 * plan.md §5) — identifies the student by number or by a scanned QR
 * token, exactly one of the two. event_day_id is required for Day and
 * Window scope; window_type only for Window scope. reason is always
 * required.
 */
export interface CreateExclusionPayload {
    eventId: number;
    scope: ExclusionScope;
    eventDayId?: number | null;
    windowType?: WindowType | null;
    reason: string;
    studentNumber?: string;
    qrToken?: string;
}

/**
 * What a successful single add reports back. The exclusion row itself is
 * refetched by the list query, so the only thing the caller needs from
 * the response is the advisory warnings — student-exclusion-feature-plan.md
 * §6a point 1's "this student already timed in for Day 2 Morning" notice.
 * Empty in the normal case; a non-empty list never means the add failed.
 */
export interface CreateExclusionResult {
    warnings: string[];
}

export interface BulkExclusionRowResult {
    row: number;
    valid: boolean;
    reasons: string[];
    studentNumber: string | null;
    scope: ExclusionScope | null;
    day: number | null;
    window: WindowType | null;
}

interface RawBulkExclusionRowResult {
    row: number;
    valid: boolean;
    reasons: string[];
    student_number: string | null;
    scope: string | null;
    day: number | null;
    window: string | null;
}

export interface BulkExclusionPreview {
    totalRows: number;
    valid: number;
    invalid: number;
    rows: BulkExclusionRowResult[];
}

interface RawBulkExclusionPreview {
    total_rows: number;
    valid: number;
    invalid: number;
    rows: RawBulkExclusionRowResult[];
}

export interface BulkExclusionResult {
    batchId: string;
    totalRows: number;
    excluded: number;
    failed: number;
    errors: Array<{ row: number; studentNumber: string | null; reasons: string[] }>;
}

interface RawBulkExclusionResult {
    batch_id: string;
    total_rows: number;
    excluded: number;
    failed: number;
    errors: Array<{ row: number; student_number: string | null; reasons: string[] }>;
}

function toBulkRow(raw: RawBulkExclusionRowResult): BulkExclusionRowResult {
    return {
        row: raw.row,
        valid: raw.valid,
        reasons: raw.reasons,
        studentNumber: raw.student_number,
        scope: raw.scope as ExclusionScope | null,
        day: raw.day,
        window: raw.window as WindowType | null,
    };
}

export const httpExclusionsRepository = {
    async listForEvent(eventId: number): Promise<ExclusionListItem[]> {
        const { data } = await httpClient.get<RawExclusionListItem[]>(`/events/${eventId}/exclusions`);
        return data.map(toExclusionListItem);
    },

    async create(payload: CreateExclusionPayload): Promise<CreateExclusionResult> {
        const { data } = await httpClient.post<{ warnings?: string[] }>('/exclusions', {
            event_id: payload.eventId,
            scope: payload.scope,
            event_day_id: payload.eventDayId ?? undefined,
            window_type: payload.windowType ?? undefined,
            reason: payload.reason,
            student_number: payload.studentNumber ?? undefined,
            qr_token: payload.qrToken ?? undefined,
        });

        return { warnings: data.warnings ?? [] };
    },

    // Soft removal on the backend (status -> removed, never a hard
    // delete — see RemoveExclusion) — the DELETE verb still fits since
    // that's the caller's intent, even though history survives.
    async remove(exclusionId: number): Promise<void> {
        await httpClient.delete(`/exclusions/${exclusionId}`);
    },

    async previewBulk(eventId: number, file: File): Promise<BulkExclusionPreview> {
        const formData = new FormData();
        formData.append('file', file);

        const { data } = await httpClient.post<RawBulkExclusionPreview>(`/events/${eventId}/exclusions/bulk/preview`, formData);

        return {
            totalRows: data.total_rows,
            valid: data.valid,
            invalid: data.invalid,
            rows: data.rows.map(toBulkRow),
        };
    },

    async createBulk(eventId: number, file: File, reason: string): Promise<BulkExclusionResult> {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('reason', reason);

        const { data } = await httpClient.post<RawBulkExclusionResult>(`/events/${eventId}/exclusions/bulk`, formData);

        return {
            batchId: data.batch_id,
            totalRows: data.total_rows,
            excluded: data.excluded,
            failed: data.failed,
            errors: data.errors.map((e) => ({ row: e.row, studentNumber: e.student_number, reasons: e.reasons })),
        };
    },
};
