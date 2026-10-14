import { useId, useState } from 'react';
import { Link } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { ArrowRight, ChevronDown, Clock, Moon, Radio, Sun, Sunrise, type LucideIcon } from 'lucide-react';

import type { EventWithDays } from '@/domain/entities';
import { pickDefaultDay, sortDaysByDate } from '@/domain/default-day';
import type { DashboardSessionCounts } from '@/infrastructure/dashboard/dashboard.repository.http';
import { AttendanceStatus, CHECK_TYPE_LABEL, SessionStatus, WINDOW_TYPE_LABEL, type WindowType } from '@/domain/enums';
import { cn, formatDate, formatTimeOfDay } from '@/lib/utils';
import { CARD, CHIP, GAP, STACK } from '@/presentation/components/spacing';
import { AnimatedCounter } from '@/presentation/components/dashboard/animated-counter';
import { ScrollableTabList } from '@/presentation/components/scrollable-tab-list';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';
import { Text } from '@/presentation/components/typography';

/** Window → a sky icon and its own hue, so a row says "morning / afternoon /
 *  evening" before any of it is read. Matches the badges on the streak strip. */
const WINDOW_STYLE: Record<WindowType, { Icon: LucideIcon; tone: Tone }> = {
    morning: { Icon: Sunrise, tone: 'amber' },
    afternoon: { Icon: Sun, tone: 'orange' },
    evening: { Icon: Moon, tone: 'violet' },
};

// "Soon"/"Ongoing"/"Ended" per the spec's own wording, rather than
// re-showing the backend's "Scheduled" label — Soon is the plainer read
// for a session that hasn't opened yet.
const SESSION_STATUS_CONTENT: Record<SessionStatus, { label: string; tone: Tone }> = {
    [SessionStatus.Scheduled]: { label: 'Soon', tone: 'amber' },
    [SessionStatus.Ongoing]: { label: 'Ongoing', tone: 'emerald' },
    [SessionStatus.Ended]: { label: 'Ended', tone: 'neutral' },
};

const ATTENDANCE_STATUS_CHIP: Partial<Record<AttendanceStatus, { label: string; tone: Tone }>> = {
    [AttendanceStatus.Present]: { label: 'You: Present', tone: 'emerald' },
    [AttendanceStatus.Late]: { label: 'You: Late', tone: 'amber' },
    [AttendanceStatus.Missed]: { label: 'You: Missed', tone: 'red' },
    [AttendanceStatus.Excluded]: { label: 'You: Excluded', tone: 'neutral' },
};

/**
 * The five numbers an ended session is summarised by. `auto` is the staff
 * (officer / CSG / SC) Present that EndSession wrote without a scan — it is
 * already inside Present, so it is shown as "of which", never added on.
 */
const SUMMARY_STATS: Array<{ key: 'present' | 'late' | 'missed' | 'excluded' | 'auto'; label: string; tone: Tone }> = [
    { key: 'present', label: 'Present', tone: 'emerald' },
    { key: 'late', label: 'Late', tone: 'amber' },
    { key: 'missed', label: 'Missed', tone: 'red' },
    { key: 'excluded', label: 'Excluded', tone: 'neutral' },
    { key: 'auto', label: 'Auto', tone: 'violet' },
];

/** "1:00 PM → 1:30 PM" that can only ever wrap *between* the two times, never inside one. */
function TimeRange({ start, end }: { start: string; end: string }) {
    return (
        <span className="inline-flex items-center gap-2 text-caption text-muted-foreground tabular-nums">
            <span className="whitespace-nowrap">{formatTimeOfDay(start)}</span>
            <ArrowRight aria-hidden className="size-3 shrink-0 opacity-60" />
            <span className="sr-only">to</span>
            <span className="whitespace-nowrap">{formatTimeOfDay(end)}</span>
        </span>
    );
}

/** Collapsed-by-default breakdown for one ended session. */
function EndedSummary({ counts }: { counts: DashboardSessionCounts }) {
    const [open, setOpen] = useState(false);
    const panelId = useId();

    return (
        <div className="mt-2 border-t border-dashed pt-2">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-expanded={open}
                aria-controls={panelId}
                className="flex w-full items-center justify-between gap-2 rounded-md text-caption font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
                <span>{open ? 'Hide details' : 'View details'}</span>
                <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} />
            </button>

            <AnimatePresence initial={false}>
                {open && (
                    <motion.div
                        id={panelId}
                        key="summary"
                        initial={{ height: 0, opacity: 0 }}
                        animate={{ height: 'auto', opacity: 1 }}
                        exit={{ height: 0, opacity: 0 }}
                        transition={{ duration: 0.25, ease: [0.16, 1, 0.3, 1] }}
                        className="overflow-hidden"
                    >
                        <dl className="grid grid-cols-2 gap-2 pt-2 sm:grid-cols-5">
                            {SUMMARY_STATS.map((stat) => (
                                <div
                                    key={stat.key}
                                    className={cn(
                                        'min-w-0 rounded-lg border px-2 py-2',
                                        TONE[stat.tone].wash,
                                        // Five tiles in a 2-col grid leaves one orphan; let it span the row.
                                        stat.key === 'auto' && 'col-span-2 sm:col-span-1',
                                    )}
                                >
                                    <dd className={cn('text-h3 leading-none font-semibold tabular-nums', TONE[stat.tone].text)}>
                                        {counts[stat.key]}
                                    </dd>
                                    <dt className="mt-1 truncate text-caption text-muted-foreground">{stat.label}</dt>
                                </div>
                            ))}
                        </dl>
                        <p className="pt-2 text-caption text-muted-foreground">
                            Auto = officers and CSG/SC admins marked present automatically; already counted in Present.
                        </p>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

/**
 * One active (ongoing) event: an event-name header, a Day 1/Day 2/…
 * carousel (a day can hold several sessions — every window's time-in and
 * time-out is its own row, per the spec), and the schedule for whichever
 * day is selected. Shared by the admin and student dashboards; what
 * differs between them is only what gets overlaid on the one session
 * that's actually running right now — the live present count for admins,
 * the viewer's own attendance status for students — passed in rather than
 * branched on internally, so this component doesn't need to know who's
 * looking at it.
 */
export function EventSchedule({
    event,
    activeSessionId = null,
    adminLiveCounts = null,
    adminEndedCounts = null,
    studentStatus = null,
}: {
    event: EventWithDays;
    /** The one session the dashboard summary reports as currently ongoing, if it belongs to this event. */
    activeSessionId?: number | null;
    /** Live counts (present, late, total…) for activeSessionId — admin dashboards only. */
    adminLiveCounts?: Pick<DashboardSessionCounts, 'present' | 'late' | 'totalStudents'> | null;
    /** Final counts per ended session id — admin dashboards only. */
    adminEndedCounts?: Record<number, DashboardSessionCounts> | null;
    /** The viewer's own attendance status for activeSessionId — student dashboards only. */
    studentStatus?: AttendanceStatus | null;
}) {
    // Calendar order, so "Day 3" never sits after "Day 6" just because it was created later.
    const days = sortDaysByDate(event.days);

    // Ongoing day → today → next upcoming → last (see pickDefaultDay).
    const defaultDay = pickDefaultDay(days);
    const defaultDayIndex = defaultDay ? days.indexOf(defaultDay) : 0;

    const [selectedDayIndex, setSelectedDayIndex] = useState(defaultDayIndex);
    const selectedDay = days[selectedDayIndex];
    const hasOngoingSession = days.some((day) => day.sessions.some((session) => session.status === SessionStatus.Ongoing));

    return (
        <div className={cn('rounded-[10px] border bg-card', CARD.pad)}>
            <div className={cn('flex items-center justify-between', GAP.grid)}>
                <Link to={`/events/${event.id}`} className="min-w-0 truncate text-h3 font-semibold hover:underline">
                    {event.name}
                </Link>
                {hasOngoingSession && (
                    // Facebook-Live style: red square tag, white text, pulsing broadcast icon.
                    <span className="inline-flex shrink-0 items-center gap-1 rounded-sm bg-red-600 px-2 py-1 text-caption leading-none font-bold tracking-wide text-white uppercase">
                        <Radio className="size-3 animate-pulse" />
                        Active
                    </span>
                )}
            </div>

            {days.length > 0 && (
                <div className="mt-4">
                <ScrollableTabList className="flex gap-4 border-b" activeKey={selectedDayIndex}>
                    {days.map((day, index) => {
                        const isSelected = index === selectedDayIndex;

                        return (
                            <button
                                key={day.id}
                                type="button"
                                role="tab"
                                onClick={() => setSelectedDayIndex(index)}
                                aria-selected={isSelected}
                                className={cn(
                                    // Plain text; the active day gets an underline in the
                                    // event's violet. -mb-px lets it sit on the strip's border.
                                    'relative -mb-px shrink-0 border-b-2 px-1 pb-2 text-small font-medium whitespace-nowrap transition-colors',
                                    isSelected
                                        ? 'border-violet-500 text-violet-600 dark:text-violet-400'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                Day {day.dayNumber}
                            </button>
                        );
                    })}
                </ScrollableTabList>
                </div>
            )}

            {selectedDay && (
                <div className={cn('mt-4', STACK.label)}>
                    <Text variant="caption">{formatDate(selectedDay.date)}</Text>

                    {selectedDay.sessions.length === 0 && <Text variant="small">No sessions scheduled for this day yet.</Text>}

                    {selectedDay.sessions.map((session) => {
                        const windowStyle = WINDOW_STYLE[session.windowType];
                        const statusContent = SESSION_STATUS_CONTENT[session.status];
                        const isTheActiveSession = session.id === activeSessionId;
                        const isLive = isTheActiveSession && session.status === SessionStatus.Ongoing;

                        return (
                            <motion.div
                                key={session.id}
                                layout
                                // 8px top and bottom around a 32px tile puts this row at
                                // exactly 48px — the tap-target height, and the same
                                // height every other list row in the app now lands on.
                                className={cn('rounded-lg border px-4 py-2', isLive && TONE.emerald.wash)}
                            >
                                <div className="flex items-center gap-3">
                                    <Tile
                                        tone={windowStyle.tone}
                                        size="sm"
                                        // A running session is the one thing on the page worth
                                        // lighting up; everything else stays tinted.
                                        variant={isLive ? 'solid' : 'soft'}
                                        Icon={windowStyle.Icon}
                                    />
                                    <div className="min-w-0 flex-1">
                                        {/* Window name on its own line; the check type is a pill and the
                                            times a unit that never splits mid-time — so a narrow phone
                                            wraps whole pieces onto the next line instead of leaving a
                                            stray "PM" behind. */}
                                        <Text variant="small" className="truncate font-medium text-foreground">
                                            {WINDOW_TYPE_LABEL[session.windowType]}
                                        </Text>
                                        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <span
                                                className={cn(
                                                    'inline-flex items-center rounded-md px-2 py-0.5 text-caption leading-none font-medium whitespace-nowrap',
                                                    TONE[windowStyle.tone].soft,
                                                )}
                                            >
                                                {CHECK_TYPE_LABEL[session.checkType]}
                                            </span>
                                            <TimeRange start={session.startTime} end={session.endTime} />
                                        </div>
                                    </div>
                                    <span className={cn(CHIP, 'h-7 px-3', TONE[statusContent.tone].chip)}>
                                        <span className="relative flex size-2">
                                            {session.status === SessionStatus.Ongoing && (
                                                <span
                                                    className={cn(
                                                        'absolute inline-flex size-full animate-ping rounded-full opacity-75',
                                                        TONE[statusContent.tone].dot,
                                                    )}
                                                />
                                            )}
                                            <span className={cn('relative inline-flex size-2 rounded-full', TONE[statusContent.tone].dot)} />
                                        </span>
                                        {statusContent.label}
                                    </span>
                                </div>

                                {isTheActiveSession && adminLiveCounts && (
                                    <div className="mt-2 border-t border-dashed pt-2">
                                        <div className="flex flex-col gap-1">
                                            <div className={cn('flex items-end', GAP.iconText)}>
                                                <span className="text-h3 leading-none font-semibold tabular-nums text-foreground">
                                                    <AnimatedCounter value={adminLiveCounts.present} />
                                                </span>
                                                <Text variant="caption">of {adminLiveCounts.totalStudents} present so far</Text>
                                            </div>
                                            <span className={cn('text-caption font-medium whitespace-nowrap tabular-nums', TONE.amber.text)}>
                                                <AnimatedCounter value={adminLiveCounts.late} /> late
                                            </span>
                                        </div>
                                        {/* Present and Late share one bar so the amber slice reads as
                                            "arrived, but after the grace period". */}
                                        <div className="mt-2 flex h-2 w-full overflow-hidden rounded-full bg-muted">
                                            <motion.div
                                                initial={{ width: 0 }}
                                                animate={{
                                                    width: `${adminLiveCounts.totalStudents > 0 ? (adminLiveCounts.present / adminLiveCounts.totalStudents) * 100 : 0}%`,
                                                }}
                                                transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
                                                className={cn('h-full', TONE.emerald.bar)}
                                            />
                                            <motion.div
                                                initial={{ width: 0 }}
                                                animate={{
                                                    width: `${adminLiveCounts.totalStudents > 0 ? (adminLiveCounts.late / adminLiveCounts.totalStudents) * 100 : 0}%`,
                                                }}
                                                transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
                                                className={cn('h-full', TONE.amber.bar)}
                                            />
                                        </div>
                                    </div>
                                )}

                                {session.status === SessionStatus.Ended && adminEndedCounts?.[session.id] && (
                                    <EndedSummary counts={adminEndedCounts[session.id]} />
                                )}

                                {isTheActiveSession && studentStatus && (
                                    <div className="mt-2 flex items-center justify-between border-t border-dashed pt-2">
                                        {ATTENDANCE_STATUS_CHIP[studentStatus] ? (
                                            <span className={cn(CHIP, TONE[ATTENDANCE_STATUS_CHIP[studentStatus]!.tone].chip)}>
                                                {ATTENDANCE_STATUS_CHIP[studentStatus]!.label}
                                            </span>
                                        ) : (
                                            <Text variant="caption">Your check-in hasn't been scanned yet.</Text>
                                        )}
                                    </div>
                                )}
                            </motion.div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/** Shown instead of EventSchedule when nothing is currently open — same quiet-state visual language as the rest of the dashboard. */
export function NoActiveEventCard() {
    return (
        <div className={cn('flex items-center rounded-[10px] border bg-card', GAP.grid, CARD.pad)}>
            <Tile tone="neutral" variant="soft" Icon={Clock} />
            <div>
                <Text variant="small" className="font-medium text-foreground">
                    No event is open
                </Text>
                <Text variant="caption">Sessions appear here once an event starts.</Text>
            </div>
        </div>
    );
}
