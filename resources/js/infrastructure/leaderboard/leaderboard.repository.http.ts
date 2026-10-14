import { httpClient } from '@/infrastructure/http/client';
import type { CheckType, WindowType } from '@/domain/enums';
import { toStreakLeaderboard, type DashboardStreakLeaderboardEntry } from '@/infrastructure/dashboard/dashboard.repository.http';

/**
 * Which window the streak points are measured in:
 *  - overall: the running streak right now
 *  - today:   the streak earned across today's sessions only
 *  - session: the running streak as of the live session, or the last one
 *             that ended when nothing is live
 */
export type LeaderboardScope = 'overall' | 'today' | 'session';

export const LEADERBOARD_SCOPES: LeaderboardScope[] = ['overall', 'today', 'session'];

/** Reads a ?scope= value, falling back to 'overall' for anything unrecognised. */
export function parseLeaderboardScope(value: string | null): LeaderboardScope {
    return LEADERBOARD_SCOPES.includes(value as LeaderboardScope) ? (value as LeaderboardScope) : 'overall';
}

export interface LeaderboardSession {
    sessionId: number;
    eventName: string;
    dayNumber: number;
    windowType: WindowType;
    checkType: CheckType;
    /** true = ongoing right now; false = the most recent one that ended. */
    isLive: boolean;
}

/** The caller's own place in the ranking. Only present for students. */
export interface LeaderboardMe {
    rank: number;
    points: number;
    /** true = on zero points, sharing the last place with everyone else on zero. */
    tied: boolean;
}

export interface Leaderboard {
    scope: LeaderboardScope;
    /** Y-m-d, only for scope 'today'. */
    date: string | null;
    session: LeaderboardSession | null;
    totalStudents: number;
    /** Students with points above zero — the only ones who can be listed. */
    rankedCount: number;
    /** Top 20. Points are `currentStreak`. */
    entries: DashboardStreakLeaderboardEntry[];
    me: LeaderboardMe | null;
}

interface RawLeaderboard {
    scope: LeaderboardScope;
    date: string | null;
    session: {
        session_id: number;
        event_name: string;
        day_number: number;
        window_type: string;
        check_type: string;
        is_live: boolean;
    } | null;
    total_students: number;
    ranked_count: number;
    entries: Parameters<typeof toStreakLeaderboard>[0];
    me: LeaderboardMe | null;
}

export const httpLeaderboardRepository = {
    async get(scope: LeaderboardScope): Promise<Leaderboard> {
        const { data } = await httpClient.get<RawLeaderboard>('/leaderboard', { params: { scope } });

        return {
            scope: data.scope,
            date: data.date,
            session: data.session
                ? {
                      sessionId: data.session.session_id,
                      eventName: data.session.event_name,
                      dayNumber: data.session.day_number,
                      windowType: data.session.window_type as WindowType,
                      checkType: data.session.check_type as CheckType,
                      isLive: data.session.is_live,
                  }
                : null,
            totalStudents: data.total_students,
            rankedCount: data.ranked_count,
            entries: toStreakLeaderboard(data.entries),
            me: data.me,
        };
    },
};
