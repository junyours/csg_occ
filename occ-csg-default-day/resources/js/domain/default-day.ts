import { SessionStatus } from '@/domain/enums';

/** Today's calendar date in Asia/Manila as "YYYY-MM-DD" — the same zone the app's session times are in. */
export function todayInManila(now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

interface PickableDay {
    date: string;
    dayNumber: number;
    // Admin sessions call it `status`, a student's own rows call it `sessionStatus`.
    sessions: { status?: string; sessionStatus?: string }[];
}

/** Days in calendar order (day number breaks a tie). */
export function sortDaysByDate<T extends { date: string; dayNumber: number }>(days: T[]): T[] {
    return [...days].sort((a, b) => a.date.slice(0, 10).localeCompare(b.date.slice(0, 10)) || a.dayNumber - b.dayNumber);
}

/**
 * Which day an event's day tabs open on:
 *
 *   1. A day with a session still ONGOING — even if that isn't today. A
 *      session left open past midnight should be the first thing anyone
 *      sees, so nobody misses that it was never closed.
 *   2. Otherwise the day whose date is today (Asia/Manila).
 *   3. Otherwise the nearest upcoming day (a gap day, or before the event).
 *   4. Otherwise the last day (the event is over).
 *
 * Deliberately date-based, never "last created" or "has unfinished sessions":
 * a day with no sessions yet still counts as today.
 */
export function pickDefaultDay<T extends PickableDay>(days: T[], today: string = todayInManila()): T | undefined {
    const sorted = sortDaysByDate(days);
    if (sorted.length === 0) return undefined;

    const ongoing = sorted.find((day) =>
        day.sessions.some((session) => (session.status ?? session.sessionStatus) === SessionStatus.Ongoing),
    );
    if (ongoing) return ongoing;

    return (
        sorted.find((day) => day.date.slice(0, 10) === today) ??
        sorted.find((day) => day.date.slice(0, 10) > today) ??
        sorted[sorted.length - 1]
    );
}
