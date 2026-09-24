import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Formats a pure calendar date (e.g. "2026-08-01T00:00:00.000000Z" from a
 * Laravel `date`-cast field) as "Aug 1, 2026".
 *
 * Deliberately does NOT do `new Date(iso).toLocaleDateString()` — that
 * would interpret the timestamp in the *viewer's* local timezone, and
 * since these values always render as midnight UTC, anyone west of the
 * Atlantic would see the previous day. There's no time-of-day here to
 * begin with (it's a date column, not an instant), so we read the
 * Y-M-D digits directly off the string and format those, sidestepping
 * timezone conversion entirely rather than doing it "correctly".
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '—';

    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

/**
 * Formats a session's "HH:mm:ss" time-of-day column (e.g. start_time /
 * end_time) as "7:00 AM". This is venue-local wall-clock time, not a real
 * instant — parsed and formatted as plain digits, same reasoning as
 * formatDate, so it never shifts across the viewer's timezone.
 */
export function formatTimeOfDay(value: string): string {
    const [hours, minutes] = value.split(':').map(Number);
    const period = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours % 12 === 0 ? 12 : hours % 12;

    return `${displayHours}:${String(minutes).padStart(2, '0')} ${period}`;
}

/**
 * Formats a real UTC instant (e.g. AttendanceRecord.scanned_at) in the
 * viewer's local time — unlike formatDate/formatTimeOfDay, this one *is*
 * a genuine timestamp, so converting to local time is correct here.
 */
export function formatScannedAt(value: string | null | undefined): string {
    if (!value) return '—';

    return new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

/**
 * Formats a penalty amount (AttendancePenalty.amount / dashboard
 * penalty_total — a decimal-cast peso figure) as "₱150.00".
 */
export function formatCurrency(value: number): string {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(value);
}

/**
 * Parses a plain "YYYY-MM-DD" calendar date as UTC midnight — the same
 * timezone-sidestepping trick as formatDate, so date arithmetic below
 * never drifts a day depending on the viewer's local timezone or DST.
 */
function parseDateOnly(value: string): Date {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);
    return new Date(Date.UTC(year, month - 1, day));
}

function formatDateOnly(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/**
 * event-day-window-edit-delete-plan.md §4.5 point 4: "must use real date
 * arithmetic, never string math" — "June 30 + 1 day" must become July 1,
 * not "June 31". Used by the Reschedule flow's default-suggestion
 * cascade (shifting later days by the same delta as the one the admin
 * just edited).
 */
export function addDaysToDateOnly(value: string, days: number): string {
    const date = parseDateOnly(value);
    date.setUTCDate(date.getUTCDate() + days);
    return formatDateOnly(date);
}

/** Whole-day difference between two "YYYY-MM-DD" dates (a - b). */
export function diffDateOnlyDays(a: string, b: string): number {
    return Math.round((parseDateOnly(a).getTime() - parseDateOnly(b).getTime()) / (24 * 60 * 60 * 1000));
}

/**
 * Student IDs are stored (and matched on) in dashed form —
 * "2023-1-05413" — but typing dashes is annoying, so this reformats
 * whatever's typed/pasted into that shape as the person goes. It works
 * off the raw digits every time rather than patching the previous
 * string, so deleting a digit right after a dash correctly collapses
 * the dash too, instead of leaving a stray "2023-" behind.
 *
 * Shared by every field that captures a student number (login, Add
 * student, Add exclusion, ...) so the mask behaves identically and
 * can't drift between them.
 */
export function formatStudentNumber(raw: string): string {
    const digits = raw.replace(/\D/g, '').slice(0, 10);
    if (digits.length <= 4) return digits;
    if (digits.length <= 5) return `${digits.slice(0, 4)}-${digits.slice(4)}`;
    return `${digits.slice(0, 4)}-${digits.slice(4, 5)}-${digits.slice(5)}`;
}
