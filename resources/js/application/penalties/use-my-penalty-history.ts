import { useQuery } from '@tanstack/react-query';

import { httpPenaltiesRepository } from '@/infrastructure/penalties/penalties.repository.http';

// Shared by the Account screen's "Penalties" row (which only needs the
// total) and the penalty-history page (which needs every entry) — one
// query key so navigating from one to the other reuses the same cached
// fetch instead of firing it twice.
export const MY_PENALTY_HISTORY_QUERY_KEY = ['my-penalty-history'];

interface UseMyPenaltyHistoryOptions {
    /** Defaults to true. Pass false for callers (e.g. non-students) that shouldn't fire the request. */
    enabled?: boolean;
}

export function useMyPenaltyHistory(options: UseMyPenaltyHistoryOptions = {}) {
    return useQuery({
        queryKey: MY_PENALTY_HISTORY_QUERY_KEY,
        queryFn: httpPenaltiesRepository.myHistory,
        enabled: options.enabled ?? true,
    });
}
