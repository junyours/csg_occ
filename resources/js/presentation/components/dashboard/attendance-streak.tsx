import { useMemo } from 'react';
import { motion, type Variants } from 'framer-motion';
import {
    CheckCircle2,
    Circle,
    Clock,
    CloudSun,
    Flame,
    MinusCircle,
    Moon,
    Sun,
    Trophy,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

import { Card, CardContent, CardHeader, CardDescription } from '@/components/ui/card';
import { useMyAttendanceHistory } from '@/application/events/use-my-attendance-history';
import type { MyAttendanceHistoryEntry } from '@/infrastructure/events/events.repository.http';
import { AttendanceStatus, CHECK_TYPE_LABEL, CheckType, WINDOW_TYPE_LABEL, WindowType } from '@/domain/enums';
import { cn } from '@/lib/utils';
import { CARD, CHIP, GAP, STACK } from '@/presentation/components/spacing';
import { Text } from '@/presentation/components/typography';

/**
 * The current/longest numbers this card shows come straight from the
 * server (StudentDashboardSummary.streak, computed by
 * ComputeAttendanceStreaks) — not recomputed here — so they can never
 * drift from what the global leaderboard is ranking on. The streak is
 * global and cross-event: it keeps running straight through an event
 * ending and into the next one's sessions, rather than resetting every
 * time the student looks at a different event.
 *
 * What *is* still assembled client-side is the pip strip below the
 * flame — a purely visual "how did I get here" timeline built from
 * useMyAttendanceHistory (every session across every event, the same
 * data the account Attendance History page uses), re-sorted oldest to
 * newest so the strip reads left-to-right the way a calendar does, with
 * the event name on each pip since a single strip can now span more
 * than one event.
 */

type PipStatus = AttendanceStatus | 'upcoming';

interface StreakPip {
    id: number;
    eventName: string;
    dateLabel: string;
    windowType: WindowType;
    checkType: CheckType;
    windowCheckLabel: string;
    status: PipStatus;
}

/** Window → little sky icon, so a glance at a pip says "morning / afternoon /
 * evening" without reading anything. Sun for morning, a part-cloudy sun for
 * afternoon, moon for evening — each with its own tint so the badge reads
 * as a distinct time of day even at a glance. */
const WINDOW_STYLE: Record<WindowType, { Icon: LucideIcon; badge: string }> = {
    [WindowType.Morning]: { Icon: Sun, badge: 'bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-300' },
    [WindowType.Afternoon]: { Icon: CloudSun, badge: 'bg-orange-100 text-orange-600 dark:bg-orange-900/50 dark:text-orange-300' },
    [WindowType.Evening]: { Icon: Moon, badge: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/50 dark:text-indigo-300' },
};

/**
 * Only Present is a "filled" reward — everything else is a quiet outline
 * card that recedes instead of competing with it for attention. Late and
 * Missed still carry their own color (so a glance at the strip tells you
 * which is which) but neither is loud enough to look like a prize the way
 * Present does; Excused/Upcoming fade further still, since neither one
 * reflects on the student either way.
 */
const STATUS_STYLE: Record<
    PipStatus,
    { Icon: LucideIcon; chip: string; icon: string; label: string }
> = {
    [AttendanceStatus.Present]: {
        Icon: CheckCircle2,
        chip: 'bg-emerald-500 text-white shadow-lg shadow-emerald-500/30',
        icon: 'text-white',
        label: 'Present',
    },
    [AttendanceStatus.Late]: {
        Icon: Clock,
        chip: 'border-2 border-amber-400/70 bg-amber-50 text-amber-600 dark:bg-amber-950/30 dark:text-amber-400',
        icon: 'text-amber-500',
        label: 'Late',
    },
    [AttendanceStatus.Absent]: {
        Icon: XCircle,
        chip: 'border-2 border-red-300/70 bg-transparent text-red-400 dark:border-red-900 dark:text-red-500/80',
        icon: 'text-red-400 dark:text-red-500/80',
        label: 'Missed',
    },
    [AttendanceStatus.Excluded]: {
        Icon: MinusCircle,
        chip: 'border-2 border-dashed border-border bg-transparent text-muted-foreground/60',
        icon: 'text-muted-foreground/60',
        label: 'Excused',
    },
    [AttendanceStatus.Pending]: {
        Icon: Circle,
        chip: 'border-2 border-dashed border-border/70 bg-transparent text-muted-foreground/40',
        icon: 'text-muted-foreground/40',
        label: 'Upcoming',
    },
    upcoming: {
        Icon: Circle,
        chip: 'border-2 border-dashed border-border/70 bg-transparent text-muted-foreground/40',
        icon: 'text-muted-foreground/40',
        label: 'Upcoming',
    },
};

const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.04, delayChildren: 0.1 } } };
const pip: Variants = {
    hidden: { opacity: 0, scale: 0.7 },
    show: { opacity: 1, scale: 1, transition: { duration: 0.35, ease: [0.16, 1, 0.3, 1] } },
};

/** How many of the most recent sessions to show in the strip — a running
 * cross-event history can grow indefinitely, and the full record already
 * has its own page (account → Attendance History); this card is a glance,
 * not the ledger. */
const MAX_PIPS = 20;

function formatShortDate(value: string): string {
    const [year, month, day] = value.slice(0, 10).split('-').map(Number);

    return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(
        new Date(Date.UTC(year, month - 1, day)),
    );
}

/** Oldest → newest, spanning every event — the same "date + start time,
 * window/check as a same-instant tiebreak" ordering the backend streak
 * computation itself walks, so the strip's left-to-right order always
 * matches what actually built the flame's number. */
function chronologicalPips(entries: MyAttendanceHistoryEntry[]): StreakPip[] {
    const windowOrder: Record<WindowType, number> = {
        [WindowType.Morning]: 1,
        [WindowType.Afternoon]: 2,
        [WindowType.Evening]: 3,
    };
    const checkOrder: Record<CheckType, number> = { [CheckType.TimeIn]: 1, [CheckType.TimeOut]: 2 };

    const sorted = [...entries].sort((a, b) => {
        const key = (entry: MyAttendanceHistoryEntry) =>
            `${entry.date.slice(0, 10)} ${entry.startTime}-${windowOrder[entry.windowType] ?? 99}-${checkOrder[entry.checkType] ?? 99}`;

        return key(a).localeCompare(key(b));
    });

    return sorted.map((entry) => ({
        id: entry.sessionId,
        eventName: entry.eventName,
        dateLabel: formatShortDate(entry.date),
        windowType: entry.windowType,
        checkType: entry.checkType,
        windowCheckLabel: `${WINDOW_TYPE_LABEL[entry.windowType]} ${CHECK_TYPE_LABEL[entry.checkType]}`,
        status: (entry.attendanceStatus ?? 'upcoming') as PipStatus,
    }));
}

export function MyAttendanceStreak({ streak }: { streak: { current: number; longest: number } }) {
    const { data: history, isLoading } = useMyAttendanceHistory();

    const pips = useMemo(() => {
        const all = chronologicalPips(history ?? []);
        return all.slice(Math.max(0, all.length - MAX_PIPS));
    }, [history]);

    const { current, longest } = streak;

    return (
        <Card className={cn('overflow-hidden', CARD.root)}>
            <CardHeader className={cn(CARD.inset, 'flex-row items-center justify-between', GAP.grid)}>
                <div className="min-w-0">
                    <CardDescription>Attendance streak</CardDescription>
                    <Text variant="small" className="truncate font-medium text-foreground">
                        Across every event
                    </Text>
                </div>

                {longest > 1 && (
                    <span className={cn(CHIP, 'border text-muted-foreground')}>
                        <Trophy className="size-4 text-amber-500" />
                        Best {longest}
                    </span>
                )}
            </CardHeader>

            <CardContent className={cn(CARD.inset, STACK.group)}>
                <div className={cn('flex items-center', GAP.grid)}>
                    <div
                        className={cn(
                            // gap-1 (4px) is the half-step: icon stacked over its own
                            // numeral inside a 64px box. Same rule the Tile follows.
                            'flex size-16 shrink-0 flex-col items-center justify-center gap-1 rounded-2xl',
                            current > 0
                                ? 'bg-orange-500 text-white shadow-lg shadow-orange-500/30'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        <Flame className={cn('size-4', current > 0 && 'fill-white')} />
                        <span className="text-h3 leading-none font-bold tabular-nums">{current}</span>
                    </div>
                    <div className="min-w-0">
                        <Text variant="small" className="font-medium text-foreground">
                            {current === 0 ? 'No streak yet' : current === 1 ? '1 session in a row' : `${current} sessions in a row`}
                        </Text>
                        <Text variant="caption">Carries through every event — it only resets on a missed or late scan.</Text>
                    </div>
                </div>

                {isLoading ? (
                    <Text variant="small">Loading your streak…</Text>
                ) : pips.length === 0 ? (
                    <Text variant="small">No sessions scheduled yet.</Text>
                ) : (
                    // -mx-1/px-1 is bleed, not spacing: it gives the window badge
                    // that overhangs each pip's top-right corner room to sit
                    // outside the pip without being clipped by the scroller.
                    <div className="relative -mx-1">
                        <div
                            className="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth px-1 pt-2 pb-2 [&::-webkit-scrollbar]:hidden"
                            style={{ scrollbarWidth: 'none' }}
                        >
                            <motion.div variants={container} initial="hidden" animate="show" className="flex gap-4">
                                {pips.map((entry) => {
                                    const style = STATUS_STYLE[entry.status];
                                    const { Icon } = style;
                                    const windowStyle = WINDOW_STYLE[entry.windowType];
                                    const WindowIcon = windowStyle.Icon;
                                    const checkLabel = entry.checkType === CheckType.TimeIn ? 'In' : 'Out';

                                    return (
                                        <motion.div
                                            key={entry.id}
                                            variants={pip}
                                            title={`${entry.eventName} · ${entry.windowCheckLabel} · ${style.label}`}
                                            className="flex shrink-0 snap-start flex-col items-center gap-2"
                                        >
                                            <div className="relative">
                                                <div className={cn('flex size-12 items-center justify-center rounded-2xl', style.chip)}>
                                                    <Icon className={cn('size-5', style.icon)} />
                                                </div>
                                                <div
                                                    className={cn(
                                                        'absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full border-2 border-card',
                                                        windowStyle.badge,
                                                    )}
                                                >
                                                    <WindowIcon className="size-3.5" />
                                                </div>
                                            </div>
                                            <Text variant="caption" className="max-w-16 truncate leading-none font-medium text-foreground">
                                                {entry.eventName}
                                            </Text>
                                            <Text variant="caption" className="leading-none whitespace-nowrap text-muted-foreground/70">
                                                {entry.dateLabel} · {checkLabel}
                                            </Text>
                                        </motion.div>
                                    );
                                })}
                            </motion.div>
                        </div>

                        {/* Fade hints that the strip scrolls, rather than a "see more" link —
                            the whole point is to glance across every session at once. */}
                        <div className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-card to-transparent" />
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
