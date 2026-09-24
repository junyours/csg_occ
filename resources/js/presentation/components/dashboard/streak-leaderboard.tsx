import { motion, type Variants } from 'framer-motion';
import { Flame, Medal, Trophy } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Card, CardContent } from '@/components/ui/card';
import type { DashboardStreakLeaderboardEntry } from '@/infrastructure/dashboard/dashboard.repository.http';
import { cn } from '@/lib/utils';
import { CARD, STACK } from '@/presentation/components/spacing';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';

const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.08, delayChildren: 0.1 } } };
const row: Variants = {
    hidden: { opacity: 0, x: -8 },
    show: { opacity: 1, x: 0, transition: { duration: 0.4, ease: [0.16, 1, 0.3, 1] } },
};

/**
 * Gold / silver / bronze — a fixed, universally-read color language
 * (medals, podiums, trophies) rather than one of the dashboard's usual
 * TONE hues, since "1st place" isn't Present/Late/Absent or any of the
 * app's existing meanings.
 */
const MEDAL_BADGE: Record<1 | 2 | 3, string> = {
    1: 'bg-gradient-to-br from-yellow-300 via-amber-400 to-yellow-600 text-amber-950 shadow-md shadow-amber-500/40',
    2: 'bg-gradient-to-br from-slate-200 via-slate-300 to-slate-400 text-slate-800 shadow-md shadow-slate-400/30',
    3: 'bg-gradient-to-br from-orange-300 via-orange-500 to-amber-700 text-white shadow-md shadow-orange-700/30',
};

const MEDAL_RING: Record<1 | 2 | 3, string> = {
    1: 'ring-2 ring-amber-400',
    2: 'ring-2 ring-slate-300',
    3: 'ring-2 ring-orange-400/70',
};

/**
 * Rank marker for the top 3 — a small rounded-full pill carrying a
 * trophy (1st) or medal (2nd/3rd) glyph, in the same "soft chip" shape
 * the streak count pill uses, rather than the boxy rounded-xl square
 * this used to be. Ranks past 3 fall back to a plain muted number chip,
 * same shape family so the row still reads as one set.
 */
function RankBadge({ rank }: { rank: number }) {
    if (rank === 1 || rank === 2 || rank === 3) {
        const Icon = rank === 1 ? Trophy : Medal;
        return (
            <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-full', MEDAL_BADGE[rank])}>
                <Icon className="size-4" />
            </span>
        );
    }

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-bold tabular-nums text-muted-foreground">
            {rank}
        </span>
    );
}

/**
 * The leaderboard's studentName arrives already combined ("First Last"),
 * unlike UserAvatar's Student prop which wants separate first/last names —
 * so initials are taken straight from the combined string's word starts
 * instead of pulling UserAvatar in for a shape it doesn't have.
 */
function initialsFromName(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return (parts[0][0] + (parts[parts.length - 1]?.[0] ?? '')).toUpperCase();
}

/**
 * Just the clock time (e.g. "1:05 PM"), in the viewer's local time —
 * latestScanAt is a genuine UTC instant, not a wall-clock string, so
 * unlike formatTimeOfDay this does convert timezones (same reasoning
 * formatScannedAt in lib/utils uses). No date attached: this sits next
 * to a leaderboard row, not a ledger entry, so only "how recently did
 * they scan" matters here, not which day.
 */
function formatScanTime(value: string | null): string | null {
    if (!value) return null;

    return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(new Date(value));
}

function StudentPhoto({ entry }: { entry: DashboardStreakLeaderboardEntry }) {
    const ring = entry.rank === 1 || entry.rank === 2 || entry.rank === 3 ? MEDAL_RING[entry.rank] : '';

    return (
        <Avatar size="sm" className={cn('shrink-0', ring)}>
            {entry.photoUrl ? <AvatarImage src={entry.photoUrl} alt={entry.studentName} /> : null}
            <AvatarFallback>{initialsFromName(entry.studentName)}</AvatarFallback>
        </Avatar>
    );
}

/**
 * The current-streak count. #1 gets the same gold treatment as its
 * badge/ring — a solid gold pill instead of plain text — so the whole
 * row reads as one matched "VIP" set rather than the flame being the
 * one piece left behind.
 */
function StreakCount({ value, isTop }: { value: number; isTop: boolean }) {
    if (isTop) {
        return (
            <div className="flex shrink-0 items-center gap-1 rounded-full bg-gradient-to-r from-amber-500 to-yellow-400 px-2.5 py-1 text-white shadow-md shadow-amber-500/40">
                <Flame className="size-4 fill-white" />
                <span className="text-small font-semibold tabular-nums">{value}</span>
            </div>
        );
    }

    return (
        <div className="flex shrink-0 items-center gap-1 text-orange-500">
            <Flame className="size-4 fill-orange-500" />
            <span className="text-small font-semibold tabular-nums text-foreground">{value}</span>
        </div>
    );
}

/**
 * Global, cross-department Top-5 by current attendance streak — every
 * role's dashboard shows the same list (see BuildStreakLeaderboard),
 * since it's a school-wide ranking rather than something scoped to
 * whoever's looking at it. Ties on streak are broken server-side by
 * scan speed, so the order here is already final; this component just
 * renders it.
 */
export function StreakLeaderboard({ entries }: { entries: DashboardStreakLeaderboardEntry[] }) {
    if (entries.length === 0) {
        return (
            <Card className={CARD.root}>
                <CardContent className={cn(CARD.inset, 'flex items-center gap-4')}>
                    <Tile tone="neutral" variant="soft" Icon={Flame} />
                    <Text variant="small">No one's on a streak yet.</Text>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={cn('overflow-hidden', CARD.root)}>
            <CardContent className={CARD.inset}>
                <motion.div variants={container} initial="hidden" animate="show" className={STACK.group}>
                    {entries.map((entry) => {
                        const isTop = entry.rank === 1;
                        const scanTime = formatScanTime(entry.latestScanAt);

                        return (
                            <motion.div
                                key={entry.studentId}
                                variants={row}
                                className={cn(
                                    'relative flex items-center justify-between gap-2 overflow-hidden',
                                    isTop &&
                                        'rounded-2xl border border-amber-400/40 bg-gradient-to-r from-amber-500/15 via-amber-400/10 to-transparent px-4 py-2 shadow-sm shadow-amber-500/20',
                                )}
                            >
                                {/* A slow diagonal sheen sweeping across the #1 row —
                                    same shimmer trick the bottom nav's Scan icon
                                    uses — is what reads as "premium" rather than
                                    just gold-colored: a static gold fill looks flat,
                                    a moving highlight looks lit. */}
                                {isTop && (
                                    <motion.span
                                        aria-hidden
                                        className="pointer-events-none absolute inset-y-0 w-1/3 -skew-x-12 bg-gradient-to-r from-transparent via-white/50 to-transparent mix-blend-overlay"
                                        animate={{ x: ['-130%', '230%'] }}
                                        transition={{ duration: 2.6, repeat: Infinity, repeatDelay: 2, ease: 'easeInOut' }}
                                    />
                                )}

                                <div className="flex min-w-0 items-center gap-4">
                                    <RankBadge rank={entry.rank} />
                                    <StudentPhoto entry={entry} />
                                    <div className="min-w-0">
                                        <Text variant="small" className="truncate font-medium text-foreground">
                                            {entry.studentName}
                                        </Text>
                                        <Text variant="caption" className="truncate">
                                            {entry.departmentCode ?? '—'}
                                            {entry.section ? ` · ${entry.section}` : ''}
                                            {scanTime ? ` · ${scanTime}` : ''}
                                        </Text>
                                    </div>
                                </div>

                                <StreakCount value={entry.currentStreak} isTop={isTop} />
                            </motion.div>
                        );
                    })}
                </motion.div>
            </CardContent>
        </Card>
    );
}
