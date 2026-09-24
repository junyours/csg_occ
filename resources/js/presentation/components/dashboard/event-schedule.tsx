import { useState } from 'react';
import { Link } from 'react-router-dom';
import { motion } from 'framer-motion';
import { Clock, Moon, Radio, Sun, Sunrise, type LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import type { EventWithDays } from '@/domain/entities';
import { AttendanceStatus, CHECK_TYPE_LABEL, SessionStatus, WINDOW_TYPE_LABEL, type WindowType } from '@/domain/enums';
import { cn, formatDate, formatTimeOfDay } from '@/lib/utils';
import { CARD, CHIP, GAP, STACK, TAP_SM } from '@/presentation/components/spacing';
import { AnimatedCounter } from '@/presentation/components/dashboard/animated-counter';
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
    [AttendanceStatus.Absent]: { label: 'You: Absent', tone: 'red' },
    [AttendanceStatus.Excluded]: { label: 'You: Excluded', tone: 'neutral' },
};

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
    studentStatus = null,
}: {
    event: EventWithDays;
    /** The one session the dashboard summary reports as currently ongoing, if it belongs to this event. */
    activeSessionId?: number | null;
    /** Present/total for activeSessionId — admin dashboards only. */
    adminLiveCounts?: { present: number; totalStudents: number } | null;
    /** The viewer's own attendance status for activeSessionId — student dashboards only. */
    studentStatus?: AttendanceStatus | null;
}) {
    const days = event.days;

    const defaultDayIndex = (() => {
        const ongoingIndex = days.findIndex((day) => day.sessions.some((session) => session.status === SessionStatus.Ongoing));
        if (ongoingIndex !== -1) return ongoingIndex;

        const upcomingIndex = days.findIndex((day) => day.sessions.some((session) => session.status !== SessionStatus.Ended));
        if (upcomingIndex !== -1) return upcomingIndex;

        return Math.max(days.length - 1, 0);
    })();

    const [selectedDayIndex, setSelectedDayIndex] = useState(defaultDayIndex);
    const selectedDay = days[selectedDayIndex];
    const hasOngoingSession = days.some((day) => day.sessions.some((session) => session.status === SessionStatus.Ongoing));

    return (
        <div className={cn('rounded-3xl border bg-card', CARD.pad)}>
            <div className={cn('flex items-center justify-between', GAP.grid)}>
                <Link to={`/events/${event.id}`} className="min-w-0 truncate text-h3 font-semibold hover:underline">
                    {event.name}
                </Link>
                {hasOngoingSession && (
                    <Badge variant="secondary" className={cn('shrink-0 gap-2 border-transparent', TONE.emerald.chip)}>
                        <Radio className="size-3" />
                        Live
                    </Badge>
                )}
            </div>

            {days.length > 0 && (
                <div className={cn('mt-4 flex overflow-x-auto pb-2', GAP.iconText)}>
                    {days.map((day, index) => {
                        const isSelected = index === selectedDayIndex;

                        return (
                            <button
                                key={day.id}
                                type="button"
                                onClick={() => setSelectedDayIndex(index)}
                                aria-pressed={isSelected}
                                className={cn(
                                    // 40px tall, 16px of horizontal padding. These are
                                    // secondary controls, so they take the 40px step rather
                                    // than the 48px one a primary button gets — still a
                                    // real tap target, still on the scale.
                                    TAP_SM,
                                    'shrink-0 rounded-full px-4 text-caption font-medium whitespace-nowrap transition-colors',
                                    isSelected
                                        ? // The selected day is the one lit element in this strip, on
                                          // the same glow the tiles use.
                                          'bg-violet-500 text-white shadow-md shadow-violet-500/30 dark:shadow-violet-500/20'
                                        : 'bg-muted text-muted-foreground hover:bg-muted/70',
                                )}
                            >
                                Day {day.dayNumber}
                            </button>
                        );
                    })}
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
                                className={cn('rounded-2xl border px-4 py-2', isLive && TONE.emerald.wash)}
                            >
                                <div className={cn('flex items-center', GAP.grid)}>
                                    <Tile
                                        tone={windowStyle.tone}
                                        size="sm"
                                        // A running session is the one thing on the page worth
                                        // lighting up; everything else stays tinted.
                                        variant={isLive ? 'solid' : 'soft'}
                                        Icon={windowStyle.Icon}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <Text variant="small" className="font-medium text-foreground">
                                            {WINDOW_TYPE_LABEL[session.windowType]} · {CHECK_TYPE_LABEL[session.checkType]}
                                        </Text>
                                        <Text variant="caption">
                                            {formatTimeOfDay(session.startTime)}–{formatTimeOfDay(session.endTime)}
                                        </Text>
                                    </div>
                                    <span className={cn(CHIP, TONE[statusContent.tone].chip)}>
                                        <span className={cn('size-2 rounded-full', TONE[statusContent.tone].dot)} />
                                        {statusContent.label}
                                    </span>
                                </div>

                                {isTheActiveSession && adminLiveCounts && (
                                    <div className="mt-2 border-t pt-2">
                                        <div className={cn('flex items-end', GAP.iconText)}>
                                            <span className="text-h3 leading-none font-semibold tabular-nums text-foreground">
                                                <AnimatedCounter value={adminLiveCounts.present} />
                                            </span>
                                            <Text variant="caption">of {adminLiveCounts.totalStudents} present so far</Text>
                                        </div>
                                        <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-muted">
                                            <motion.div
                                                initial={{ width: 0 }}
                                                animate={{
                                                    width: `${adminLiveCounts.totalStudents > 0 ? (adminLiveCounts.present / adminLiveCounts.totalStudents) * 100 : 0}%`,
                                                }}
                                                transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
                                                className={cn('h-full rounded-full', TONE.emerald.bar)}
                                            />
                                        </div>
                                    </div>
                                )}

                                {isTheActiveSession && studentStatus && (
                                    <div className="mt-2 flex items-center justify-between border-t pt-2">
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
        <div className={cn('flex items-center rounded-3xl border bg-card', GAP.grid, CARD.pad)}>
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
