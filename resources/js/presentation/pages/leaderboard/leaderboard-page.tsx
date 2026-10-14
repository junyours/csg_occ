import { motion } from 'framer-motion';
import { Flame, Radio } from 'lucide-react';
import { useSearchParams } from 'react-router-dom';

import { Card, CardContent } from '@/components/ui/card';
import { useAuthStore } from '@/application/auth/auth.store';
import { useLeaderboard } from '@/application/leaderboard/use-leaderboard';
import { CHECK_TYPE_LABEL, WINDOW_TYPE_LABEL } from '@/domain/enums';
import {
    LEADERBOARD_SCOPES,
    parseLeaderboardScope,
    type Leaderboard,
    type LeaderboardScope,
} from '@/infrastructure/leaderboard/leaderboard.repository.http';
import { cn, formatDate } from '@/lib/utils';
import { StreakPodium, StreakRankRow } from '@/presentation/components/dashboard/streak-podium';
import { CARD, STACK } from '@/presentation/components/spacing';
import { Tile } from '@/presentation/components/tile';
import { Heading, Text } from '@/presentation/components/typography';

const SCOPE_LABEL: Record<LeaderboardScope, string> = {
    overall: 'Overall',
    today: 'Today',
    session: 'Session',
};

const listContainer = { hidden: {}, show: { transition: { staggerChildren: 0.04 } } };
const listItem = {
    hidden: { opacity: 0, y: 6 },
    show: { opacity: 1, y: 0, transition: { duration: 0.3, ease: [0.16, 1, 0.3, 1] as const } },
};

export function LeaderboardPage() {
    const myId = useAuthStore((state) => state.student?.id ?? null);
    const [params, setParams] = useSearchParams();
    const scope = parseLeaderboardScope(params.get('scope'));
    const { data, isLoading, isError } = useLeaderboard(scope);

    function selectScope(next: LeaderboardScope) {
        // replace: flipping tabs shouldn't pile up history entries the back button has to unwind.
        setParams(next === 'overall' ? {} : { scope: next }, { replace: true });
    }

    return (
        <div className={STACK.section}>
            <div>
                <Heading level="h1">Leaderboard</Heading>
                <Text variant="small">Points are your attendance streak — the longer it runs, the higher you climb.</Text>
            </div>

            <div className={STACK.group}>
                <div role="tablist" aria-label="Leaderboard period" className="grid grid-cols-3 gap-1 rounded-full bg-muted p-1">
                    {LEADERBOARD_SCOPES.map((option) => {
                        const selected = option === scope;

                        return (
                            <button
                                key={option}
                                type="button"
                                role="tab"
                                aria-selected={selected}
                                onClick={() => selectScope(option)}
                                className={cn(
                                    'h-10 rounded-full text-small font-medium transition-colors',
                                    selected ? 'bg-emerald-500 text-white shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {SCOPE_LABEL[option]}
                            </button>
                        );
                    })}
                </div>

                <ScopeCaption scope={scope} data={data} />
            </div>

            {isLoading && <div aria-hidden className="h-96 animate-pulse rounded-[10px] bg-muted" />}

            {isError && (
                <Card className={CARD.root}>
                    <CardContent className={cn(CARD.inset, 'flex items-center gap-4')}>
                        <Tile tone="red" variant="soft" Icon={Flame} />
                        <Text variant="small">The leaderboard didn't load. Pull down to refresh, or try again in a moment.</Text>
                    </CardContent>
                </Card>
            )}

            {data && <LeaderboardBody data={data} myId={myId} />}
        </div>
    );
}

function ScopeCaption({ scope, data }: { scope: LeaderboardScope; data: Leaderboard | undefined }) {
    if (scope === 'overall') {
        return <Text variant="caption">Current streak across this semester.</Text>;
    }

    if (scope === 'today') {
        return (
            <Text variant="caption">
                {data?.date ? `Streaks built across today's sessions · ${formatDate(data.date)}` : "Streaks built across today's sessions."}
            </Text>
        );
    }

    const session = data?.session;
    if (!session) return <Text variant="caption">Streak standing after the live session, or the last one that ended.</Text>;

    return (
        <div className="flex items-center gap-2">
            {session.isLive && (
                <span className="relative flex size-2 shrink-0">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                    <span className="relative inline-flex size-2 rounded-full bg-emerald-500" />
                </span>
            )}
            <Text variant="caption" className="min-w-0 truncate">
                {session.isLive ? 'Live now' : 'Last ended'} · {session.eventName} · Day {session.dayNumber}{' '}
                {WINDOW_TYPE_LABEL[session.windowType]} {CHECK_TYPE_LABEL[session.checkType]}
            </Text>
        </div>
    );
}

function LeaderboardBody({ data, myId }: { data: Leaderboard; myId: number | null }) {
    if (data.entries.length === 0) {
        const message =
            data.scope === 'today'
                ? 'No one has built a streak in today’s sessions yet.'
                : data.scope === 'session'
                  ? data.session
                      ? 'No one is on a streak at this session yet.'
                      : 'No session has run yet — this fills in once one starts.'
                  : "No one's on a streak yet.";

        return (
            <Card className={CARD.root}>
                <CardContent className={cn(CARD.inset, 'flex items-center gap-4')}>
                    <Tile tone="neutral" variant="soft" Icon={data.scope === 'session' ? Radio : Flame} />
                    <Text variant="small">{message}</Text>
                </CardContent>
            </Card>
        );
    }

    const podium = data.entries.filter((entry) => entry.rank <= 3);
    const rest = data.entries.filter((entry) => entry.rank > 3);

    return (
        <div className={STACK.group}>
            <Card className={cn('overflow-hidden', CARD.root)}>
                <CardContent className={CARD.inset}>
                    <StreakPodium entries={podium} highlightId={myId} />
                </CardContent>
            </Card>

            {rest.length > 0 && (
                <motion.div key={data.scope} variants={listContainer} initial="hidden" animate="show" className={STACK.label}>
                    {rest.map((entry) => (
                        <motion.div key={entry.studentId} variants={listItem}>
                            <StreakRankRow entry={entry} highlight={entry.studentId === myId} />
                        </motion.div>
                    ))}
                </motion.div>
            )}

            <Text variant="caption" className="text-center">
                Top {data.entries.length} of {data.totalStudents.toLocaleString()} students
            </Text>
        </div>
    );
}
