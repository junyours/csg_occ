import { ChevronRight, Flame } from 'lucide-react';
import { Link } from 'react-router-dom';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useAuthStore } from '@/application/auth/auth.store';
import type { DashboardStreakLeaderboardEntry } from '@/infrastructure/dashboard/dashboard.repository.http';
import { cn } from '@/lib/utils';
import { CARD, STACK } from '@/presentation/components/spacing';
import { StreakPodium, StreakRankRow } from '@/presentation/components/dashboard/streak-podium';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';

/**
 * Dashboard preview of the global streak leaderboard: the top three on a
 * podium, ranks 4 and 5 as rows, and "View more" through to the full
 * Leaderboard screen (Overall / Today / Session, top 20, your own rank).
 *
 * Every role's dashboard shows the same list (see BuildStreakLeaderboard)
 * since it's a school-wide ranking. Points are the attendance streak; ties
 * are broken server-side by scan speed, so the order here is already final.
 */
export function StreakLeaderboard({ entries }: { entries: DashboardStreakLeaderboardEntry[] }) {
    const myId = useAuthStore((state) => state.student?.id ?? null);

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

    const podium = entries.filter((entry) => entry.rank <= 3);
    const rest = entries.filter((entry) => entry.rank > 3);

    return (
        <Card className={cn('overflow-hidden', CARD.root)}>
            <CardContent className={cn(CARD.inset, STACK.group)}>
                <StreakPodium entries={podium} highlightId={myId} />

                {rest.length > 0 && (
                    <div className={STACK.label}>
                        {rest.map((entry) => (
                            <StreakRankRow key={entry.studentId} entry={entry} highlight={entry.studentId === myId} />
                        ))}
                    </div>
                )}

                <Button asChild variant="outline" className="w-full">
                    <Link to="/leaderboard">
                        View more
                        <ChevronRight />
                    </Link>
                </Button>
            </CardContent>
        </Card>
    );
}
