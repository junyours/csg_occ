import { Flame } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import type { DashboardStreakLeaderboardEntry } from '@/infrastructure/dashboard/dashboard.repository.http';
import { cn } from '@/lib/utils';
import { AvatarLightbox } from '@/presentation/components/avatar-lightbox';
import { Text } from '@/presentation/components/typography';

/**
 * Shared building blocks for the streak leaderboard: the top-3 podium and
 * the numbered rows under it. Used by the dashboard card (top 5) and the
 * full Leaderboard screen (top 20) so the two always look like one thing.
 *
 * Points are the attendance streak, shown with the same flame the rest of
 * the app uses for streaks.
 */

export function initialsFromName(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    return (parts[0][0] + (parts[parts.length - 1]?.[0] ?? '')).toUpperCase();
}

/**
 * Just the clock time down to the second, in the viewer's local time —
 * latestScanAt is a real UTC instant. Seconds are shown because streak
 * ties are broken by scan speed server-side, so two people who both
 * scanned at "2:45 PM" may genuinely differ by seconds.
 */
export function formatScanTime(value: string | null): string | null {
    if (!value) return null;

    return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date(value));
}

/** Gold / silver / bronze: a fixed, universally-read color language, not one of the app's TONE hues. */
const PODIUM = {
    1: {
        ring: 'ring-[3px] ring-amber-400 shadow-lg shadow-amber-500/30',
        badge: 'bg-gradient-to-br from-yellow-300 via-amber-400 to-yellow-600 text-amber-950',
        avatar: 'size-20',
    },
    2: {
        ring: 'ring-[3px] ring-slate-300',
        badge: 'bg-gradient-to-br from-slate-200 via-slate-300 to-slate-400 text-slate-800',
        avatar: 'size-16',
    },
    3: {
        ring: 'ring-[3px] ring-orange-400/80',
        badge: 'bg-gradient-to-br from-orange-300 via-orange-500 to-amber-700 text-white',
        avatar: 'size-16',
    },
} as const;

function Points({ value, className }: { value: number; className?: string }) {
    return (
        <span className={cn('inline-flex shrink-0 items-center gap-1 text-small font-semibold tabular-nums text-foreground', className)}>
            <Flame className="size-4 fill-orange-500 text-orange-500" />
            {value}
        </span>
    );
}

function PodiumSpot({ entry, highlight }: { entry: DashboardStreakLeaderboardEntry; highlight: boolean }) {
    const style = PODIUM[entry.rank as 1 | 2 | 3];
    const isFirst = entry.rank === 1;

    return (
        // pt on 2nd/3rd drops them below 1st so the three read as a podium.
        <div className={cn('flex min-w-0 flex-1 flex-col items-center text-center', !isFirst && 'pt-6')}>
            <div className="relative">
                <AvatarLightbox photoUrl={entry.photoUrl} name={entry.studentName}>
                    <Avatar className={cn('shrink-0', style.avatar, style.ring)}>
                        {entry.photoUrl ? <AvatarImage src={entry.photoUrl} alt={entry.studentName} /> : null}
                        <AvatarFallback className={isFirst ? 'text-h3' : 'text-body'}>{initialsFromName(entry.studentName)}</AvatarFallback>
                    </Avatar>
                </AvatarLightbox>
                <span
                    className={cn(
                        'absolute -bottom-2 left-1/2 flex size-6 -translate-x-1/2 items-center justify-center rounded-full text-caption font-bold tabular-nums ring-2 ring-card',
                        style.badge,
                    )}
                >
                    {entry.rank}
                </span>
            </div>

            {/* Names wrap onto as many lines as they need — a name is the one
                thing on this card people are looking for, so it is never cut
                off with an ellipsis. break-words lets a single very long word
                (a long surname) wrap too instead of overflowing its column. */}
            <span
                className={cn(
                    'mt-4 mb-1 block w-full text-center leading-snug font-medium break-words text-foreground',
                    isFirst ? 'text-small' : 'text-caption',
                    highlight && 'text-primary',
                )}
            >
                {entry.studentName}
                {highlight && <span className="block text-caption font-semibold text-primary">You</span>}
            </span>
            <Points value={entry.currentStreak} />
        </div>
    );
}

/** Top three, 2nd – 1st – 3rd. Fewer than three entries just leaves the missing spots empty. */
export function StreakPodium({ entries, highlightId }: { entries: DashboardStreakLeaderboardEntry[]; highlightId?: number | null }) {
    const bySlot = (rank: number) => entries.find((entry) => entry.rank === rank);
    const slots = [bySlot(2), bySlot(1), bySlot(3)];

    return (
        <div className="flex items-start justify-center gap-1 pb-2">
            {slots.map((entry, index) =>
                entry ? (
                    <PodiumSpot key={entry.studentId} entry={entry} highlight={entry.studentId === highlightId} />
                ) : (
                    <div key={`empty-${index}`} className="flex-1" aria-hidden />
                ),
            )}
        </div>
    );
}

/** Rank 4 and below: number, avatar, name + caption, points. */
export function StreakRankRow({
    entry,
    highlight,
    showScanTime = true,
}: {
    entry: DashboardStreakLeaderboardEntry;
    highlight: boolean;
    showScanTime?: boolean;
}) {
    const scanTime = showScanTime ? formatScanTime(entry.latestScanAt) : null;

    return (
        <div
            className={cn(
                'flex items-center gap-3 rounded-xl border bg-card px-3 py-3',
                highlight && 'border-primary/50 bg-primary/5 ring-1 ring-primary/30',
            )}
        >
            <span className="w-6 shrink-0 text-center text-small font-semibold tabular-nums text-muted-foreground">{entry.rank}</span>
            <AvatarLightbox photoUrl={entry.photoUrl} name={entry.studentName}>
                <Avatar className="size-10 shrink-0">
                    {entry.photoUrl ? <AvatarImage src={entry.photoUrl} alt={entry.studentName} /> : null}
                    <AvatarFallback>{initialsFromName(entry.studentName)}</AvatarFallback>
                </Avatar>
            </AvatarLightbox>
            {/* No truncation anywhere in here: the name wraps, and the
                department · section and the scan time each get their own
                line so neither is squeezed out by the other. */}
            <div className="min-w-0 flex-1">
                <p className="text-small leading-snug font-medium break-words text-foreground">
                    {entry.studentName}
                    {highlight && <span className="ml-2 text-caption font-semibold text-primary">You</span>}
                </p>
                <Text variant="caption" className="break-words">
                    {entry.departmentCode ?? '—'}
                    {entry.section ? ` · ${entry.section}` : ''}
                </Text>
                {scanTime && (
                    <Text variant="caption" className="tabular-nums">
                        {scanTime}
                    </Text>
                )}
            </div>
            <Points value={entry.currentStreak} />
        </div>
    );
}
