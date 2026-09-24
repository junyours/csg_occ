import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { motion, type Variants } from 'framer-motion';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    CheckCircle2,
    Clock,
    Flame,
    MinusCircle,
    Moon,
    QrCode,
    Radio,
    ScanLine,
    Star,
    Sun,
    Sunrise,
    Trophy,
    Users,
    Wallet,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAuthStore } from '@/application/auth/auth.store';
import { useDashboard } from '@/application/dashboard/use-dashboard';
import { useActiveEvents } from '@/application/events/use-active-events';
import type {
    DashboardActiveSession,
    DashboardDepartmentAttendance,
    DashboardDepartmentPenalty,
    DashboardSummary,
    OfficerDashboardSummary,
    StudentDashboardSummary,
} from '@/infrastructure/dashboard/dashboard.repository.http';
import { AttendanceStatus, CHECK_TYPE_LABEL, Role, WINDOW_TYPE_LABEL } from '@/domain/enums';
import { cn, formatCurrency, formatDate, formatTimeOfDay } from '@/lib/utils';
import { Heading, Text } from '@/presentation/components/typography';
import { AnimatedCounter } from '@/presentation/components/dashboard/animated-counter';
import { MyAttendanceStreak } from '@/presentation/components/dashboard/attendance-streak';
import { StreakLeaderboard } from '@/presentation/components/dashboard/streak-leaderboard';
import { EventSchedule, NoActiveEventCard } from '@/presentation/components/dashboard/event-schedule';
import { RadialGauge, SegmentedRing } from '@/presentation/components/dashboard/gauges';
import { Leaderboard, type LeaderboardEntry } from '@/presentation/components/dashboard/leaderboard';
import { Glow, StatTile, Tile } from '@/presentation/components/tile';
import { CARD, CHIP, GAP, SPACE, STACK, TAP, ROW } from '@/presentation/components/spacing';
import { DEPARTMENT_TONES, TONE, type Tone } from '@/presentation/components/tone';

function isOfficerSummary(summary: DashboardSummary): summary is OfficerDashboardSummary {
    return summary.role === Role.Officer;
}

function isStudentSummary(summary: DashboardSummary): summary is StudentDashboardSummary {
    return summary.role === Role.Student;
}

// One shared stagger so every dashboard variant enters the same way —
// the single orchestrated motion moment for this page. Each direct child
// of `container` should carry the `item` variant.
const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.07, delayChildren: 0.05 } } };
const item: Variants = {
    hidden: { opacity: 0, y: 10 },
    show: { opacity: 1, y: 0, transition: { duration: 0.45, ease: [0.16, 1, 0.3, 1] } },
};

export function DashboardPage() {
    const student = useAuthStore((state) => state.student);
    const { data, isLoading, isError } = useDashboard();

    if (!student) return null;

    return (
        // Every gap on this page is a multiple of 8 (see spacing.ts): 32px
        // between sections, 16px between components inside one, 8px between
        // a label and what it labels.
        <div className={STACK.section}>
            <div>
                <Heading level="h1">Dashboard</Heading>
                <Text variant="small">{subtitleFor(data)}</Text>
            </div>

            {isLoading && <DashboardSkeleton />}

            {isError && (
                <Card className={cn('border', CARD.root, TONE.red.wash)}>
                    <CardContent className={cn(CARD.inset, 'flex items-center', GAP.grid)}>
                        <Tile tone="red" Icon={AlertTriangle} variant="soft" />
                        <div>
                            <Text variant="small" className="font-medium text-foreground">
                                The dashboard didn't load
                            </Text>
                            <Text variant="caption">Pull down to refresh, or try again in a moment.</Text>
                        </div>
                    </CardContent>
                </Card>
            )}

            {data && isOfficerSummary(data) && <OfficerDashboard summary={data} />}
            {data && isStudentSummary(data) && <StudentDashboard summary={data} />}
            {data && !isOfficerSummary(data) && !isStudentSummary(data) && <AdminDashboard summary={data} role={student.role} />}
        </div>
    );
}

function subtitleFor(data: DashboardSummary | undefined): string {
    if (!data) return 'Loading your dashboard…';
    if (isOfficerSummary(data)) return 'Your scanning activity across every session.';
    if (isStudentSummary(data)) return 'Your attendance record and current standing.';
    if (data.scope === 'department' && data.department) return `${data.department.name} (${data.department.code}) overview.`;
    return 'Every department, every event, at a glance.';
}

/**
 * Shaped like the real thing rather than a "Loading…" line, so the page
 * doesn't jump a full screen-height when data lands.
 */
function DashboardSkeleton() {
    return (
        <div className={STACK.group} aria-hidden>
            <div className="h-64 animate-pulse rounded-3xl bg-muted" />
            <div className={cn('grid grid-cols-2 sm:grid-cols-4', GAP.grid)}>
                {[0, 1, 2, 3].map((index) => (
                    <div key={index} className="h-20 animate-pulse rounded-2xl bg-muted" />
                ))}
            </div>
        </div>
    );
}

/* ---------------------------------------------------------------------- */
/* Tiers — the light gamification layer. Derived entirely client-side from
   numbers the API already returns; no backend concept of "level" exists
   or needs to. Each tier now carries a Tone rather than a loose text
   class, so the hero's ring, glow and badge all pick up the same hue from
   one source instead of three hand-matched strings. */
/* ---------------------------------------------------------------------- */

interface Tier {
    label: string;
    Icon: LucideIcon;
    tone: Tone;
}

function attendanceTier(rate: number | null): Tier {
    if (rate === null) return { label: 'No sessions yet', Icon: Clock, tone: 'neutral' };
    if (rate >= 95) return { label: 'Perfect record', Icon: Trophy, tone: 'emerald' };
    if (rate >= 85) return { label: 'Reliable', Icon: Star, tone: 'emerald' };
    if (rate >= 70) return { label: 'Getting there', Icon: Flame, tone: 'amber' };
    return { label: 'Needs attention', Icon: AlertTriangle, tone: 'red' };
}

function contributionTier(percentage: number, totalScans: number): Tier {
    if (totalScans === 0) return { label: 'No scans yet', Icon: Clock, tone: 'neutral' };
    if (percentage >= 50) return { label: 'Top scanner', Icon: Trophy, tone: 'violet' };
    if (percentage >= 25) return { label: 'Strong contributor', Icon: Star, tone: 'violet' };
    return { label: 'Getting started', Icon: Flame, tone: 'orange' };
}

// Purely a glance icon next to the window label — Morning/Afternoon/Evening
// already carry the real meaning via WINDOW_TYPE_LABEL, this just makes it
// scannable half a second faster.
const WINDOW_TYPE_ICON: Record<string, LucideIcon> = {
    morning: Sunrise,
    afternoon: Sun,
    evening: Moon,
};

/**
 * Minutes between now and a session's end_time, for the "N min left" chip.
 * start_time/end_time are venue-local wall-clock strings (see
 * formatTimeOfDay in lib/utils), not real instants, so this assumes the
 * viewer's device clock reads venue-local time — true for anyone actually
 * on campus scanning in or checking their own session, which is the only
 * audience this chip is shown to. Returns null once the window has passed
 * by the viewer's own clock, so a stale/wrong client clock just hides the
 * chip instead of showing a confusing negative number — the session's
 * real open/closed state still comes from the server, not this.
 */
function minutesRemaining(endTime: string): number | null {
    const [hours, minutes] = endTime.split(':').map(Number);
    const end = new Date();
    end.setHours(hours, minutes, 0, 0);
    const diff = Math.round((end.getTime() - Date.now()) / 60000);
    return diff > 0 ? diff : null;
}

/* ---------------------------------------------------------------------- */
/* Student                                                                 */
/* ---------------------------------------------------------------------- */

function StudentDashboard({ summary }: { summary: StudentDashboardSummary }) {
    const { present, late, absent, excluded } = summary.totals;
    const tracked = present + late + absent;
    const rate = tracked > 0 ? Math.round(((present + late) / tracked) * 100) : null;
    const tier = attendanceTier(rate);

    // Every event CSG hasn't ended yet — plural, since nothing stops more
    // than one being open at once (see useActiveEvents). Used for the
    // event schedule list below; the streak itself is global and
    // cross-event now, so it no longer depends on this.
    const { activeEvents, isLoading: isActiveEventsLoading } = useActiveEvents();

    const stats: { status: AttendanceStatus; value: number; Icon: LucideIcon; tone: Tone }[] = [
        { status: AttendanceStatus.Present, value: present, Icon: CheckCircle2, tone: 'emerald' },
        { status: AttendanceStatus.Late, value: late, Icon: Clock, tone: 'amber' },
        { status: AttendanceStatus.Absent, value: absent, Icon: XCircle, tone: 'red' },
        { status: AttendanceStatus.Excluded, value: excluded, Icon: MinusCircle, tone: 'neutral' },
    ];

    return (
        <motion.div variants={container} initial="hidden" animate="show" className={STACK.section}>
            <motion.div variants={item}>
                <ScoreHero
                    value={rate}
                    tier={tier}
                    eyebrow="Your attendance score"
                    caption={
                        tracked > 0
                            ? `Present or late for ${present + late} of ${tracked} tracked sessions`
                            : 'Nothing tracked yet — check back after your first session.'
                    }
                    segments={
                        tracked > 0
                            ? [
                                  { value: present, tone: 'emerald' },
                                  { value: late, tone: 'amber' },
                                  { value: absent, tone: 'red' },
                              ]
                            : undefined
                    }
                />
            </motion.div>

            <motion.div variants={item} className={cn('grid grid-cols-2 sm:grid-cols-4', GAP.grid)}>
                {stats.map((stat) => (
                    <StatTile key={stat.status} label={statusLabel(stat.status)} value={stat.value} Icon={stat.Icon} tone={stat.tone} />
                ))}
            </motion.div>

            <motion.div variants={item}>
                <MyAttendanceStreak streak={summary.streak} />
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Trophy} tone="orange">
                    Streak leaderboard
                </SectionLabel>
                <StreakLeaderboard entries={summary.streakLeaderboard} />
            </motion.div>

            {/* 8px from the label to its content, 16px between the cards inside
                it — so the label reads as belonging to the group rather than
                floating equidistant between two of them. */}
            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={CalendarDays} tone="sky">
                    Event schedule
                </SectionLabel>
                <div className={STACK.group}>
                    {isActiveEventsLoading && <Text variant="small">Loading…</Text>}
                    {!isActiveEventsLoading && activeEvents.length === 0 && <NoActiveEventCard />}
                    {activeEvents.map((event) => (
                        <EventSchedule
                            key={event.id}
                            event={event}
                            activeSessionId={summary.activeSession?.eventId === event.id ? summary.activeSession.sessionId : null}
                            studentStatus={
                                summary.activeSession?.eventId === event.id
                                    ? (summary.activeSessionStatus ?? AttendanceStatus.Pending)
                                    : null
                            }
                        />
                    ))}
                </div>
            </motion.div>
        </motion.div>
    );
}

/* ---------------------------------------------------------------------- */
/* Officer                                                                 */
/* ---------------------------------------------------------------------- */

function OfficerDashboard({ summary }: { summary: OfficerDashboardSummary }) {
    const others = Math.max(summary.totalScans - summary.myScans, 0);
    const tier = contributionTier(summary.contributionPercentage, summary.totalScans);

    return (
        <motion.div variants={container} initial="hidden" animate="show" className={STACK.section}>
            <motion.div variants={item}>
                <ScoreHero
                    value={summary.totalScans > 0 ? summary.contributionPercentage : null}
                    tier={tier}
                    eyebrow="Your share of all scans"
                    caption={`${summary.myScans} of ${summary.totalScans} total scans recorded across every officer`}
                />
            </motion.div>

            <motion.div variants={item} className={cn('grid grid-cols-2', GAP.grid)}>
                <StatTile label="Your scans" value={summary.myScans} Icon={ScanLine} tone="violet" variant="solid" />
                <StatTile label="Everyone else" value={others} Icon={Users} tone="neutral" />
            </motion.div>

            <motion.div variants={item}>
                <Card className={CARD.root}>
                    {/* No pb- override: Card's own 16/24px header-to-content gap
                        is already the right step, and hand-tuning it per card is
                        how the old 12px/10px values crept in. */}
                    <CardHeader className={CARD.inset}>
                        <CardDescription>You vs. everyone else</CardDescription>
                    </CardHeader>
                    <CardContent className={CARD.inset}>
                        <SplitBar mineLabel="You" mine={summary.myScans} othersLabel="Other officers" others={others} />
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Radio} tone="emerald">
                    Current session
                </SectionLabel>
                <ActiveSessionCard session={summary.activeSession} />
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Trophy} tone="orange">
                    Streak leaderboard
                </SectionLabel>
                <StreakLeaderboard entries={summary.streakLeaderboard} />
            </motion.div>
        </motion.div>
    );
}

/* ---------------------------------------------------------------------- */
/* Admin (System Admin / CSG Admin / SC Admin)                            */
/* ---------------------------------------------------------------------- */

// Same tier AttendancePenaltyPolicy::viewAny gates the admin ledger on —
// an SC Admin sees this same PenaltyStrip card (their dashboard is
// department-scoped, not role-restricted), but linking them through to
// /penalties would just 403, so the click-through is gated separately
// here rather than assumed from "got an AdminDashboard at all".
const PENALTY_VIEW_ROLES: Role[] = [Role.SystemAdmin, Role.CsgAdmin];

function AdminDashboard({ summary, role }: { summary: Extract<DashboardSummary, { scope: 'global' | 'department' }>; role: Role }) {
    const { present, late, absent } = summary.overallAttendanceCounts;
    const { activeEvents, isLoading: isActiveEventsLoading } = useActiveEvents();

    const penaltyEntries: LeaderboardEntry[] = summary.penaltyByEvent.map((row) => ({
        id: row.eventId,
        href: `/events/${row.eventId}`,
        label: row.eventName,
        value: formatCurrency(row.penaltyTotal),
        percentage: row.percentageOfOverall,
    }));

    return (
        <motion.div variants={container} initial="hidden" animate="show" className={STACK.section}>
            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={CalendarDays} tone="sky">
                    Event schedule
                </SectionLabel>
                <div className={STACK.group}>
                    {isActiveEventsLoading && <Text variant="small">Loading…</Text>}
                    {!isActiveEventsLoading && activeEvents.length === 0 && (
                        <QuietHero
                            totalStudents={summary.totalStudents}
                            eventsCount={summary.eventsCount}
                            departmentsCount={summary.attendanceByDepartment.length}
                        />
                    )}
                    {activeEvents.map((event) => (
                        <EventSchedule
                            key={event.id}
                            event={event}
                            activeSessionId={summary.activeSession?.eventId === event.id ? summary.activeSession.sessionId : null}
                            adminLiveCounts={summary.activeSession?.eventId === event.id ? summary.activeSessionCounts : null}
                        />
                    ))}
                </div>
            </motion.div>

            <motion.div variants={item}>
                <Card className={cn('overflow-hidden', CARD.root)}>
                    <CardHeader className={CARD.inset}>
                        <CardDescription>Attendance across all events</CardDescription>
                    </CardHeader>
                    <CardContent className={CARD.inset}>
                        <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-center sm:justify-around">
                            {/* 144, not 148 — a ring is a box like anything else, and
                                an odd diameter can't be centred on the grid. */}
                            <SegmentedRing
                                size={SPACE.sm * 9}
                                strokeWidth={SPACE.sm}
                                segments={[
                                    { value: present, className: TONE.emerald.stroke },
                                    { value: late, className: TONE.amber.stroke },
                                    { value: absent, className: TONE.red.stroke },
                                ]}
                            >
                                <span className="text-h2 font-semibold tabular-nums text-foreground">
                                    <AnimatedCounter value={present + late + absent} />
                                </span>
                                <span className="text-caption text-muted-foreground">tracked</span>
                            </SegmentedRing>

                            <div className={cn('grid w-full sm:w-auto sm:min-w-56', GAP.grid)}>
                                <StatTile label="Present" value={present} Icon={CheckCircle2} tone="emerald" variant="solid" />
                                <StatTile label="Late" value={late} Icon={Clock} tone="amber" />
                                <StatTile label="Absent" value={absent} Icon={XCircle} tone="red" />
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Building2} tone="violet">
                    Attendance by department
                </SectionLabel>
                <DepartmentComposition rows={summary.attendanceByDepartment} penaltyByDepartment={summary.penaltyByDepartment} />
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Wallet} tone="amber">
                    Penalties across all events
                </SectionLabel>
                <div className={STACK.group}>
                    <PenaltyStrip total={summary.penaltyTotal} canViewPenalties={PENALTY_VIEW_ROLES.includes(role)} />
                    <Leaderboard entries={penaltyEntries} emptyLabel="No penalties recorded yet." tone="amber" />
                </div>
            </motion.div>

            <motion.div variants={item} className={STACK.label}>
                <SectionLabel Icon={Trophy} tone="orange">
                    Streak leaderboard
                </SectionLabel>
                <StreakLeaderboard entries={summary.streakLeaderboard} />
            </motion.div>
        </motion.div>
    );
}

/**
 * Section headers now carry the same tinted squircle the cards below them
 * do, at the smallest size — so a section and its contents are visibly one
 * unit, and the eye can find "the penalties part" by color alone while
 * scrolling.
 */
function SectionLabel({ Icon, tone, children }: { Icon: LucideIcon; tone: Tone; children: ReactNode }) {
    return (
        // gap-2 is the icon-to-text step, used identically here, in every stat
        // tile, and in every list row — that consistency is what makes an icon
        // and its label read as one object.
        <div className={cn('flex items-center', GAP.iconText)}>
            <Tile tone={tone} size="sm" variant="soft" Icon={Icon} />
            <Text variant="small" className="font-medium text-foreground">
                {children}
            </Text>
        </div>
    );
}

/* ---------------------------------------------------------------------- */
/* Department composition — combines each department's share of overall  */
/* tracked attendance with its own Present/Late/Absent split, so one card */
/* answers both "how much of overall attendance is this department" and  */
/* "how is that department doing". Kept on this app's existing status    */
/* palette (emerald/amber/red) rather than a new color scheme, since     */
/* that mapping already means Present/Late/Absent everywhere else in the */
/* app (badges, the ring above, etc.).                                   */
/* ---------------------------------------------------------------------- */

function DepartmentComposition({
    rows,
    penaltyByDepartment,
}: {
    rows: DashboardDepartmentAttendance[];
    penaltyByDepartment: DashboardDepartmentPenalty[];
}) {
    if (rows.length === 0) {
        return (
            <Card className={CARD.root}>
                <CardContent className={cn(CARD.inset, 'flex items-center', GAP.grid)}>
                    <Tile tone="neutral" variant="soft" Icon={Building2} />
                    <Text variant="small">No attendance tracked yet.</Text>
                </CardContent>
            </Card>
        );
    }

    const penaltyByDept = new Map(penaltyByDepartment.map((row) => [row.departmentId, row]));

    return (
        // Every grid item here needs `min-w-0`: grid tracks default to
        // `min-width: auto`, so a single long, unwrappable line inside any
        // card (the penalty amount used to be one) was enough to make the
        // whole track — and with it the whole page — grow past the
        // viewport on mobile instead of wrapping or truncating in place.
        <motion.div
            variants={container}
            initial="hidden"
            animate="show"
            className={cn('grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3', GAP.grid)}
        >
            {rows.map((dept, index) => {
                const tracked = dept.present + dept.late + dept.absent;
                const presentPct = tracked > 0 ? (dept.present / tracked) * 100 : 0;
                const latePct = tracked > 0 ? (dept.late / tracked) * 100 : 0;
                const absentPct = tracked > 0 ? 100 - presentPct - latePct : 0;
                const penalty = penaltyByDept.get(dept.departmentId);
                const tone = DEPARTMENT_TONES[index % DEPARTMENT_TONES.length];

                return (
                    <motion.div key={dept.departmentId} variants={item} className="min-w-0">
                        <Card className={cn('h-full min-w-0 overflow-hidden', CARD.root)}>
                            <CardContent className={CARD.inset}>
                                <div className={cn('flex items-center justify-between', GAP.grid)}>
                                    <div className={cn('flex min-w-0 items-center', GAP.iconText)}>
                                        {/* The one solid tile on this card: a department's code is
                                            how you identify the row, so that's what gets lit. */}
                                        <Tile tone={tone} size="md" variant="solid">
                                            <span className="text-xs font-bold tabular-nums">{dept.departmentCode.slice(0, 2)}</span>
                                        </Tile>
                                        <div className="min-w-0">
                                            <span className="block truncate text-small font-medium text-foreground">
                                                {dept.departmentCode}
                                            </span>
                                            <Text variant="caption" className="truncate">
                                                {dept.departmentName}
                                            </Text>
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <span className={cn('block text-h3 leading-none font-semibold tabular-nums', TONE[tone].text)}>
                                            {Math.round(dept.percentageOfOverall)}%
                                        </span>
                                        <Text variant="caption">of overall</Text>
                                    </div>
                                </div>

                                {/* gap-px is a hairline between segments, not spacing —
                                    it separates two colours, it doesn't position anything. */}
                                <div className="mt-4 flex h-2 w-full items-center gap-px overflow-hidden rounded-full bg-muted">
                                    {presentPct > 0 && (
                                        <motion.div
                                            initial={{ width: 0 }}
                                            animate={{ width: `${presentPct}%` }}
                                            transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1] }}
                                            className={cn('h-full rounded-full', TONE.emerald.bar)}
                                        />
                                    )}
                                    {latePct > 0 && (
                                        <motion.div
                                            initial={{ width: 0 }}
                                            animate={{ width: `${latePct}%` }}
                                            transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1], delay: 0.05 }}
                                            className={cn('h-full rounded-full', TONE.amber.bar)}
                                        />
                                    )}
                                    {absentPct > 0 && (
                                        <motion.div
                                            initial={{ width: 0 }}
                                            animate={{ width: `${absentPct}%` }}
                                            transition={{ duration: 0.6, ease: [0.16, 1, 0.3, 1], delay: 0.1 }}
                                            className={cn('h-full rounded-full', TONE.red.bar)}
                                        />
                                    )}
                                </div>

                                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-caption text-muted-foreground">
                                    <LegendDot tone="emerald">Present {Math.round(presentPct)}%</LegendDot>
                                    <LegendDot tone="amber">Late {Math.round(latePct)}%</LegendDot>
                                    <LegendDot tone="red">Absent {Math.round(absentPct)}%</LegendDot>
                                    <span className="ml-auto tabular-nums">{tracked} tracked</span>
                                </div>

                                {penalty && penalty.penaltyTotal > 0 && (
                                    <div
                                        className={cn(
                                            'mt-4 flex flex-wrap items-center justify-between gap-x-2 gap-y-2 rounded-xl border px-4 py-2',
                                            TONE.red.wash,
                                        )}
                                    >
                                        <span className={cn('flex shrink-0 items-center text-caption text-muted-foreground', GAP.iconText)}>
                                            <Wallet className="size-4" /> Penalties
                                        </span>
                                        <span className="flex min-w-0 flex-wrap items-baseline justify-end gap-x-2 text-small font-semibold tabular-nums text-foreground">
                                            <span className="shrink-0">{formatCurrency(penalty.penaltyTotal)}</span>
                                            <span className="shrink-0 font-normal text-muted-foreground">
                                                ({Math.round(penalty.percentageOfOverall)}% of all)
                                            </span>
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </motion.div>
                );
            })}
        </motion.div>
    );
}

function LegendDot({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span className={cn('flex items-center', GAP.iconText)}>
            <span className={cn('size-2 rounded-full', TONE[tone].dot)} />
            {children}
        </span>
    );
}

/* ---------------------------------------------------------------------- */
/* Shared pieces                                                          */
/* ---------------------------------------------------------------------- */

/**
 * The hero. The ring's arc, the pool of light behind it and the tier badge
 * all take their hue from one Tier — so a student at 96% sits inside a
 * green glow and one at 40% sits inside a red one, and the state is legible
 * from across the room before a single number is read.
 *
 * The arc was a flat foreground stroke before; it's a gradient now, which
 * is the same trick the streak tile plays (saturated fill + its own shadow)
 * scaled up to the largest element on the page.
 */
interface ScoreHeroSegment {
    value: number;
    tone: Tone;
}

function ScoreHero({
    value,
    tier,
    eyebrow,
    caption,
    segments,
}: {
    value: number | null;
    tier: Tier;
    eyebrow: string;
    caption: string;
    /**
     * When provided, the ring is drawn as a Present/Late/Absent-style
     * multi-color donut (see SegmentedRing) instead of a single gradient
     * arc — so a student whose 67% is "1 present, 1 late, 1 absent" sees
     * a green, amber and red slice rather than one flat red arc that only
     * reflects the tier, not the actual mix behind it.
     */
    segments?: ScoreHeroSegment[];
}) {
    const { Icon, tone } = tier;

    return (
        // Shadow lives on this outer layer, which stays un-clipped. The
        // glow's blur used to be clipped by the same element that carried
        // the card's elevation, so the drop shadow either disappeared or
        // came out looking like a hard-edged box instead of a soft lift —
        // splitting "clip the glow" (inner layer) from "cast the shadow"
        // (outer layer) is what gives this card the same soft elevation
        // every other card in the app has.
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1] }}
            className="rounded-3xl border bg-card"
        >
            <div className={cn('overflow-hidden rounded-[inherit]', CARD.pad)}>
                <div className={cn('flex flex-col items-center text-center', GAP.grid)}>
                    <Text variant="caption">{eyebrow}</Text>

                    {segments ? (
                        <SegmentedRing
                            size={SPACE.sm * 11}
                            strokeWidth={SPACE.sm}
                            segments={segments.map((segment) => ({ value: segment.value, className: TONE[segment.tone].stroke }))}
                        >
                            {value !== null ? (
                                <>
                                    <span className="text-display font-semibold tabular-nums text-foreground">
                                        <AnimatedCounter value={value} format={(n) => `${Math.round(n)}`} />
                                    </span>
                                    <span className="text-caption text-muted-foreground">percent</span>
                                </>
                            ) : (
                                <span className="text-h3 text-muted-foreground">—</span>
                            )}
                        </SegmentedRing>
                    ) : (
                        <RadialGauge value={value ?? 0} gradient={TONE[tone].gradient}>
                            {value !== null ? (
                                <>
                                    <span className="text-display font-semibold tabular-nums text-foreground">
                                        <AnimatedCounter value={value} format={(n) => `${Math.round(n)}`} />
                                    </span>
                                    <span className="text-caption text-muted-foreground">percent</span>
                                </>
                            ) : (
                                <span className="text-h3 text-muted-foreground">—</span>
                            )}
                        </RadialGauge>
                    )}

                    <motion.div
                        initial={{ opacity: 0, scale: 0.9 }}
                        animate={{ opacity: 1, scale: 1 }}
                        transition={{ delay: 0.6, type: 'spring', stiffness: 260, damping: 18 }}
                        // Built from the same parts as CHIP rather than from CHIP
                        // itself: this is the one pill on the page carrying
                        // body-size text, and layering `text-small` over CHIP's
                        // `text-caption` would leave two font-size utilities for
                        // tailwind-merge to pick between. It still takes the 48px
                        // control height instead of the 32px chip height.
                        className={cn(
                            'inline-flex shrink-0 items-center gap-2 rounded-full px-4 text-small font-medium shadow-none',
                            TAP,
                            TONE[tone].solid,
                        )}
                    >
                        <Icon className="size-4" />
                        {tier.label}
                    </motion.div>

                    <Text variant="small" className="max-w-xs">
                        {caption}
                    </Text>
                </div>
            </div>
        </motion.div>
    );
}

/**
 * Shown to admins when nothing is running. The three counts get the tile
 * treatment rather than the old bordered mini-boxes, so a quiet dashboard
 * still looks like the same product as a busy one.
 */
function QuietHero({
    totalStudents,
    eventsCount,
    departmentsCount,
}: {
    totalStudents: number;
    eventsCount: number;
    departmentsCount: number;
}) {
    return (
        <motion.div
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, ease: [0.16, 1, 0.3, 1] }}
            className={cn('rounded-3xl border bg-card shadow-sm', CARD.pad)}
        >
            <div className={cn('flex items-center text-muted-foreground', GAP.iconText)}>
                <Tile tone="neutral" size="sm" variant="soft" Icon={Moon} />
                <Text variant="small">No session is currently open.</Text>
            </div>
            {/* Penalty total lives in one place only — the dedicated "Penalties across
                all events" section below — rather than repeated here, so the figure
                the person sees at the top of the page always matches the figure they
                see when they scroll down to check it. */}
            <div className={cn('mt-4 grid sm:grid-cols-3', GAP.grid)}>
                <StatTile label="Students" value={totalStudents} Icon={Users} tone="violet" variant="solid" />
                <StatTile label="Events" value={eventsCount} Icon={CalendarDays} tone="sky" />
                <StatTile label="Departments" value={departmentsCount} Icon={Building2} tone="orange" />
            </div>
        </motion.div>
    );
}

function PenaltyStrip({ total, canViewPenalties }: { total: number; canViewPenalties: boolean }) {
    const isOwed = total > 0;
    const tone: Tone = isOwed ? 'red' : 'emerald';

    const content = (
        <Card className={cn('border', CARD.root, TONE[tone].wash, canViewPenalties && 'transition-transform active:scale-[0.99]')}>
            <CardContent className={cn(CARD.inset, 'flex items-center', GAP.grid)}>
                <Tile tone={tone} size="lg" variant="solid" Icon={Wallet} />
                <div className="min-w-0">
                    <CardDescription>{isOwed ? 'Outstanding penalty balance' : 'Nothing owed'}</CardDescription>
                    <span className={cn('block text-h1 leading-tight font-semibold tabular-nums', TONE[tone].text)}>
                        <AnimatedCounter value={total} format={formatCurrency} />
                    </span>
                </div>
            </CardContent>
        </Card>
    );

    // Reversed penalties are excluded from this total (see
    // BuildDashboardSummary::penaltyTotal), so the click-through carries
    // status=active — landing on the unfiltered "all" ledger would show
    // a bigger number than the one just tapped, and the two would look
    // out of sync.
    return canViewPenalties ? (
        <Link to="/penalties?status=active" className="block">
            {content}
        </Link>
    ) : (
        content
    );
}

function SplitBar({ mineLabel, mine, othersLabel, others }: { mineLabel: string; mine: number; othersLabel: string; others: number }) {
    const total = mine + others;
    const minePercent = total > 0 ? (mine / total) * 100 : 0;

    return (
        <div className={STACK.label}>
            <div className="flex h-2 overflow-hidden rounded-full bg-muted">
                <motion.div
                    className={cn('h-full rounded-full', TONE.violet.bar)}
                    initial={{ width: 0 }}
                    animate={{ width: `${minePercent}%` }}
                    transition={{ duration: 0.9, ease: [0.16, 1, 0.3, 1], delay: 0.15 }}
                />
            </div>
            <div className="flex items-center justify-between text-small">
                <LegendDot tone="violet">
                    {mineLabel} · <span className="font-medium text-foreground">{mine}</span>
                </LegendDot>
                <span className={cn('flex items-center text-muted-foreground', GAP.iconText)}>
                    <span className="size-2 rounded-full bg-muted-foreground/40" />
                    {othersLabel} · <span className="font-medium text-foreground">{others}</span>
                </span>
            </div>
        </div>
    );
}

function ActiveSessionCard({ session, status }: { session: DashboardActiveSession | null; status?: AttendanceStatus | null }) {
    if (!session) {
        return (
            <Card className={CARD.root}>
                <CardContent className={cn(CARD.inset, 'flex items-center', GAP.grid)}>
                    <Tile tone="neutral" variant="soft" Icon={Moon} />
                    <div>
                        <Text variant="small" className="font-medium text-foreground">
                            Nothing open right now
                        </Text>
                        <Text variant="caption">This fills in as soon as a session starts.</Text>
                    </div>
                </CardContent>
            </Card>
        );
    }

    const remaining = minutesRemaining(session.endTime);
    const WindowIcon = WINDOW_TYPE_ICON[session.windowType] ?? Clock;

    return (
        <Card className={cn('border', CARD.root, TONE.emerald.wash)}>
            <CardHeader className={cn(CARD.inset, GAP.iconText)}>
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <span className="relative flex size-2">
                            <span className={cn('absolute inline-flex size-full animate-ping rounded-full opacity-75', TONE.emerald.bar)} />
                            <span className={cn('relative inline-flex size-2 rounded-full', TONE.emerald.bar)} />
                        </span>
                        <CardDescription>Live now</CardDescription>
                    </div>
                    <SessionStatusBadge status={status ?? null} />
                </div>
                <CardTitle className="text-h2">
                    <Link to={`/events/${session.eventId}`} className="hover:underline">
                        {session.eventName}
                    </Link>
                </CardTitle>
            </CardHeader>
            <CardContent className={cn(CARD.inset, STACK.group)}>
                <Text variant="small">
                    Day {session.eventDay.dayNumber} — {formatDate(session.eventDay.date)}
                </Text>

                {/* 8px top and bottom around a 32px tile = a 48px row. */}
                <div className={cn(ROW, 'border bg-card')}>
                    <Tile tone="sky" size="sm" variant="soft" Icon={WindowIcon} />
                    <div className="min-w-0 flex-1">
                        <Text variant="small" className="font-medium text-foreground">
                            {WINDOW_TYPE_LABEL[session.windowType]} · {CHECK_TYPE_LABEL[session.checkType]}
                        </Text>
                        <Text variant="caption">
                            {formatTimeOfDay(session.startTime)}–{formatTimeOfDay(session.endTime)}
                        </Text>
                    </div>
                    {remaining !== null && (
                        <span className={cn(CHIP, TONE.amber.chip)}>{remaining} min left</span>
                    )}
                </div>

                {status === AttendanceStatus.Pending && (
                    <div className={cn('flex items-center text-small font-medium', GAP.iconText, TONE.violet.text)}>
                        <QrCode className="size-4 shrink-0" />
                        Scan your QR at the gate to check in.
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

const STATUS_TONE: Record<AttendanceStatus, Tone> = {
    [AttendanceStatus.Present]: 'emerald',
    [AttendanceStatus.Late]: 'amber',
    [AttendanceStatus.Absent]: 'red',
    [AttendanceStatus.Excluded]: 'neutral',
    [AttendanceStatus.Pending]: 'neutral',
};

function SessionStatusBadge({ status }: { status: AttendanceStatus | null }) {
    if (!status) return null;

    return (
        <Badge variant="secondary" className={cn('border-transparent', TONE[STATUS_TONE[status]].chip)}>
            {statusLabel(status)}
        </Badge>
    );
}

function statusLabel(status: AttendanceStatus): string {
    const labels: Record<AttendanceStatus, string> = {
        [AttendanceStatus.Present]: 'Present',
        [AttendanceStatus.Late]: 'Late',
        [AttendanceStatus.Absent]: 'Absent',
        [AttendanceStatus.Excluded]: 'Excluded',
        [AttendanceStatus.Pending]: 'Pending',
    };
    return labels[status];
}
