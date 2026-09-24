import axios from 'axios';

import { httpClient } from '@/infrastructure/http/client';
import type { AttendanceStatus, ScanOutcome } from '@/domain/enums';
import type { AttendanceSession } from '@/domain/entities';

/**
 * The student as returned by POST /sessions/{session}/scan — a smaller,
 * scan-specific shape (not the full domain Student) since this is only
 * ever used for the officer's face-to-QR cross-check on the scanning
 * screen, not for anything that edits or persists student data.
 */
export interface ScannedStudent {
    id: number;
    studentNumber: string;
    lastName: string;
    firstName: string;
    middleName: string | null;
    suffix: string | null;
    yearLevel: number | null;
    section: string | null;
    departmentCode: string | null;
    departmentName: string | null;
    photoUrl: string | null;
}

export interface ScanResult {
    recordId: number;
    outcome: ScanOutcome;
    /** Present/Late/Absent classification for the session's single check. */
    status: AttendanceStatus;
    scannedAt: string | null;
    student: ScannedStudent;
}

/**
 * Thrown for a rejected scan (malformed/stale QR, session not open,
 * excluded student, etc.) — carries the officer-facing reason straight
 * from the API's `{ message }` error body so the scan screen can show
 * *why* a badge was rejected, not just that it was.
 *
 * Also used by `reverseScan` below, which fails the same way (409 when
 * the session has closed, 404 when someone else already reversed the
 * same row) and wants the same treatment: show the server's sentence.
 */
export class ScanError extends Error {}

/** Turns any axios failure carrying a `{ message }` body into a ScanError. */
function toScanError(error: unknown): unknown {
    if (axios.isAxiosError(error) && typeof error.response?.data?.message === 'string') {
        return new ScanError(error.response.data.message);
    }
    return error;
}

interface RawScanStudent {
    id: number;
    student_number: string;
    last_name: string;
    first_name: string;
    middle_name: string | null;
    suffix: string | null;
    year_level: number | null;
    section: string | null;
    photo_url: string | null;
    department?: { id: number; name: string; code: string } | null;
}

interface RawScanResponse {
    id: number;
    status: string;
    scanned_at: string | null;
    outcome: string;
    student: RawScanStudent;
}

function toScanResult(raw: RawScanResponse): ScanResult {
    return {
        recordId: raw.id,
        outcome: raw.outcome as ScanOutcome,
        status: raw.status as AttendanceStatus,
        scannedAt: raw.scanned_at,
        student: {
            id: raw.student.id,
            studentNumber: raw.student.student_number,
            lastName: raw.student.last_name,
            firstName: raw.student.first_name,
            middleName: raw.student.middle_name,
            suffix: raw.student.suffix,
            yearLevel: raw.student.year_level,
            section: raw.student.section,
            departmentCode: raw.student.department?.code ?? null,
            departmentName: raw.student.department?.name ?? null,
            photoUrl: raw.student.photo_url,
        },
    };
}

/**
 * The outcome of undoing a scan (POST .../records/{record}/reverse). The
 * attendance row itself is deleted server-side — "pending" in this app is
 * the *absence* of a record — so there's nothing to map back into a
 * ScanResult; `recordId` is the row that just stopped existing, which is
 * what the scan screen needs to drop it from its list.
 */
export interface ScanReversal {
    recordId: number;
    studentId: number;
    reason: string;
    reversedAt: string | null;
}

interface RawScanReversal {
    record_id: number;
    student_id: number;
    status: string;
    reason: string;
    reversed_by: number;
    reversed_at: string | null;
}

export interface CreateSessionPayload {
    window_type: string;
    check_type: string;
    start_time: string;
    end_time: string;
    grace_minutes?: number | null;
    penalty_late_amount?: number | null;
    penalty_absent_amount?: number | null;
}

// event-day-window-edit-delete-plan.md §4.3a: every field optional — a
// PATCH may touch just one of them (e.g. only grace_minutes). Mirrors
// CreateSessionPayload's shape.
export interface UpdateSessionPayload {
    window_type?: string;
    check_type?: string;
    start_time?: string;
    end_time?: string;
    grace_minutes?: number | null;
    penalty_late_amount?: number | null;
    penalty_absent_amount?: number | null;
}

// §4.3a/§4.4: how many active Window-scope exclusions were soft-removed —
// only non-zero when this delete removed the last remaining check of its
// window.
export interface DeleteSessionResult {
    sessionId: number;
    exclusionsRemoved: number;
}

interface RawDeleteSessionResult {
    session_id: number;
    exclusions_removed: number;
}

// §4.3b: the whole-window delete convenience — every still-Scheduled
// check of one window_type on one day, in a single call.
export interface DeleteWindowResult {
    eventDayId: number;
    windowType: string;
    sessionsDeleted: number;
    exclusionsRemoved: number;
}

interface RawDeleteWindowResult {
    event_day_id: number;
    window_type: string;
    sessions_deleted: number;
    exclusions_removed: number;
}

interface RawCreatedSession {
    id: number;
    event_day_id: number;
    window_type: string;
    check_type: string;
    start_time: string;
    end_time: string;
    grace_minutes: number;
    penalty_late_amount: string | null;
    penalty_absent_amount: string | null;
    status: string;
}

function toAttendanceSession(raw: RawCreatedSession): AttendanceSession {
    return {
        id: raw.id,
        eventDayId: raw.event_day_id,
        windowType: raw.window_type as AttendanceSession['windowType'],
        checkType: raw.check_type as AttendanceSession['checkType'],
        startTime: raw.start_time,
        endTime: raw.end_time,
        graceMinutes: raw.grace_minutes,
        penaltyLateAmount: Number(raw.penalty_late_amount ?? 0),
        penaltyAbsentAmount: Number(raw.penalty_absent_amount ?? 0),
        status: raw.status as AttendanceSession['status'],
    };
}

export const httpSessionsRepository = {
    // event_day_id is the EventDay this window belongs to — mirrors
    // POST /event-days/{eventDay}/sessions (SessionController::store).
    async createSession(eventDayId: number, payload: CreateSessionPayload): Promise<AttendanceSession> {
        const { data } = await httpClient.post<RawCreatedSession>(`/event-days/${eventDayId}/sessions`, payload);
        return toAttendanceSession(data);
    },

    // Both are manual, human-triggered transitions — no clock-based
    // auto-start/auto-end. A device's clock can run ahead or behind, and
    // shared hosting has no reliable cron/queue worker to poll for it
    // anyway, so a CSG Admin taps the button on the session card instead.
    async startSession(sessionId: number): Promise<AttendanceSession> {
        const { data } = await httpClient.post<RawCreatedSession>(`/sessions/${sessionId}/start`);
        return toAttendanceSession(data);
    },

    async endSession(sessionId: number): Promise<AttendanceSession> {
        const { data } = await httpClient.post<RawCreatedSession>(`/sessions/${sessionId}/end`);
        return toAttendanceSession(data);
    },

    // event-day-window-edit-delete-plan.md §4.3a: a single check, only
    // while it's still Scheduled (UpdateSession). A 422 with a
    // `window_type` validation error means the edited (window_type,
    // check_type) pair now collides with a sibling check on the same day.
    async updateSession(sessionId: number, payload: UpdateSessionPayload): Promise<AttendanceSession> {
        const { data } = await httpClient.patch<RawCreatedSession>(`/sessions/${sessionId}`, payload);
        return toAttendanceSession(data);
    },

    // §4.3a/§4.4: refused with 409 if the session isn't Scheduled, or its
    // event has already ended. Cascades the window's exclusion
    // soft-removal if this was its last remaining check.
    async deleteSession(sessionId: number): Promise<DeleteSessionResult> {
        const { data } = await httpClient.delete<RawDeleteSessionResult>(`/sessions/${sessionId}`);
        return { sessionId: data.session_id, exclusionsRemoved: data.exclusions_removed };
    },

    // §4.3b: deletes every still-Scheduled check of one window_type on
    // one day. Refused whole (409) if even one check in the window has
    // started or ended.
    async deleteWindow(eventDayId: number, windowType: string): Promise<DeleteWindowResult> {
        const { data } = await httpClient.delete<RawDeleteWindowResult>(`/event-days/${eventDayId}/windows/${windowType}`);
        return {
            eventDayId: data.event_day_id,
            windowType: data.window_type,
            sessionsDeleted: data.sessions_deleted,
            exclusionsRemoved: data.exclusions_removed,
        };
    },

    async scan(sessionId: number, token: string): Promise<ScanResult> {
        try {
            const { data } = await httpClient.post<RawScanResponse>(`/sessions/${sessionId}/scan`, { token });
            return toScanResult(data);
        } catch (error) {
            throw toScanError(error);
        }
    },

    /**
     * The last few badges read into *this one session* — GET
     * /sessions/{session}/recent-scans.
     *
     * Read from the server rather than accumulated in the browser: a
     * device-local list can't know that a scan was reversed (by this
     * officer or another one), and it carries over rows from whatever
     * session that device happened to scan earlier in the day. The API
     * answers for one session only, so the strip can't show another
     * session's or another event's students.
     *
     * Shaped identically to the single-scan response, so both go through
     * `toScanResult` rather than a second near-identical parser.
     */
    async recentScans(sessionId: number, limit: number): Promise<ScanResult[]> {
        const { data } = await httpClient.get<RawScanResponse[]>(`/sessions/${sessionId}/recent-scans`, {
            params: { limit },
        });

        return data.map(toScanResult);
    },

    /**
     * Undo a scan that shouldn't have counted — the QR belonged to
     * someone who isn't the person who presented it. The student goes
     * back to pending for this session and can be scanned again by its
     * real owner.
     *
     * `reason` is optional: the officer has a queue in front of them, and
     * the server fills in a default rather than blocking the line on
     * typing. When one is given it must be at least 3 characters (the
     * API's own rule) — the scan screen offers one-tap presets so the
     * common cases land something meaningful.
     */
    async reverseScan(sessionId: number, recordId: number, reason?: string): Promise<ScanReversal> {
        try {
            const { data } = await httpClient.post<RawScanReversal>(
                `/sessions/${sessionId}/records/${recordId}/reverse`,
                reason ? { reason } : {},
            );

            return {
                recordId: data.record_id,
                studentId: data.student_id,
                reason: data.reason,
                reversedAt: data.reversed_at,
            };
        } catch (error) {
            throw toScanError(error);
        }
    },
};
