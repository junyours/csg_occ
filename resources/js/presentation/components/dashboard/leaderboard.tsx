import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { motion, type Variants } from 'framer-motion';

import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { CARD, STACK } from '@/presentation/components/spacing';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';

export interface LeaderboardEntry {
    id: string | number;
    label: string;
    href?: string;
    /** Formatted right-aligned value, e.g. a currency amount or a count. */
    value: ReactNode;
    /** 0–100, this row's share of the whole list. */
    percentage: number;
}

const container: Variants = { hidden: {}, show: { transition: { staggerChildren: 0.08, delayChildren: 0.1 } } };
const row: Variants = {
    hidden: { opacity: 0, x: -8 },
    show: { opacity: 1, x: 0, transition: { duration: 0.4, ease: [0.16, 1, 0.3, 1] } },
};

/**
 * Rank marker. #1 is the only lit one — the glow is the medal, which is
 * both quieter and more consistent with the rest of the dashboard than an
 * actual gold/silver/bronze ramp would be. Everything below it is a plain
 * numeral, because "not first" doesn't need three shades of its own.
 */
function RankBadge({ rank, tone }: { rank: number; tone: Tone }) {
    if (rank === 0) {
        return (
            <Tile tone={tone} size="sm" variant="solid">
                <span className="text-xs font-bold tabular-nums">1</span>
            </Tile>
        );
    }

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-muted text-xs font-bold tabular-nums text-muted-foreground">
            {rank + 1}
        </span>
    );
}

/**
 * Ranked list — the shared visual language for "how is the whole split up",
 * used identically for penalties-by-event and attendance-by-department so
 * the two read as the same kind of breakdown.
 */
export function Leaderboard({
    entries,
    emptyLabel,
    tone = 'amber',
}: {
    entries: LeaderboardEntry[];
    emptyLabel: string;
    /** Hue for the leader's tile and the share bars — lets each leaderboard
     *  inherit its section's color instead of every ranked list on the page
     *  looking identical. */
    tone?: Tone;
}) {
    if (entries.length === 0) {
        return (
            // No `pt-6` here anymore: Card already carries 16/24px of vertical
            // padding, so adding it made an empty card 32/48px deep at the top
            // and 16/24px at the bottom — lopsided, and off the scale.
            <Card className={CARD.root}>
                <CardContent className={CARD.inset}>
                    <Text variant="small">{emptyLabel}</Text>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={cn('overflow-hidden', CARD.root)}>
            <CardContent className={CARD.inset}>
                <motion.div variants={container} initial="hidden" animate="show" className={STACK.group}>
                    {entries.map((entry, index) => {
                        const inner = (
                            <>
                                <div className="flex items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <RankBadge rank={index} tone={tone} />
                                        <Text
                                            variant="small"
                                            className={cn('truncate font-medium text-foreground', entry.href && 'group-hover:underline')}
                                        >
                                            {entry.label}
                                        </Text>
                                    </div>
                                    <span className="shrink-0 font-semibold tabular-nums text-foreground">{entry.value}</span>
                                </div>
                                {/* ml-10 = the 32px rank badge + the 8px gap beside it,
                                    so the bar starts exactly under the label rather than
                                    at the old 42px eyeballed offset. */}
                                <div className="ml-10 flex items-center gap-2">
                                    <Progress
                                        value={entry.percentage}
                                        className="h-2"
                                        indicatorClassName={index === 0 ? TONE[tone].bar : 'bg-muted-foreground/30'}
                                    />
                                    <span className="w-10 shrink-0 text-right text-caption tabular-nums text-muted-foreground">
                                        {Math.round(entry.percentage)}%
                                    </span>
                                </div>
                            </>
                        );

                        return (
                            <motion.div key={entry.id} variants={row} className={STACK.label}>
                                {entry.href ? (
                                    <Link to={entry.href} className={cn('group block', STACK.label)}>
                                        {inner}
                                    </Link>
                                ) : (
                                    inner
                                )}
                            </motion.div>
                        );
                    })}
                </motion.div>
            </CardContent>
        </Card>
    );
}
