import { useQuery } from '@tanstack/react-query';

import { httpLeaderboardRepository, type LeaderboardScope } from '@/infrastructure/leaderboard/leaderboard.repository.http';

export const LEADERBOARD_QUERY_KEY = ['leaderboard'];

/**
 * The Leaderboard screen and the bottom-nav's "#2234 You" pill both read
 * this with the same scope, so they share one cache entry — one request
 * feeds both. No staleTime override: the app-wide 60s default (app.tsx)
 * already matches the server's 60s ranking cache.
 */
export function useLeaderboard(scope: LeaderboardScope, enabled = true) {
    return useQuery({
        queryKey: [...LEADERBOARD_QUERY_KEY, scope],
        queryFn: () => httpLeaderboardRepository.get(scope),
        enabled,
    });
}
