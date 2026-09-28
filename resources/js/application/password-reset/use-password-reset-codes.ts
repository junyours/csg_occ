import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { httpPasswordResetRepository } from '@/infrastructure/password-reset/password-reset.repository.http';

export const PASSWORD_RESET_CODES_QUERY_KEY = ['password-reset-codes'];

/**
 * Loads the list once when the screen opens; after that only the Refresh
 * button (or the clear button) talks to the server. Password resets are rare,
 * so everything that could fetch on its own is switched off — no polling, no
 * refetch when the tab regains focus or the network reconnects, no retry
 * after a failure. Each of those would be an API call nobody asked for.
 *
 * (The countdown on each code is a local clock, not a request.)
 */
export function usePasswordResetCodes() {
    return useQuery({
        queryKey: PASSWORD_RESET_CODES_QUERY_KEY,
        queryFn: () => httpPasswordResetRepository.listCodes(),
        refetchInterval: false,
        refetchOnWindowFocus: false,
        refetchOnReconnect: false,
        retry: false,
        // Visiting the screen always shows current data: never reuse a
        // cached list from an earlier visit as if it were fresh.
        staleTime: 0,
        gcTime: 0,
    });
}

/** "Clear used & expired" — replaces the cached list with the one the server returns. */
export function useClearPasswordResetCodes() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: () => httpPasswordResetRepository.clearDeadCodes(),
        onSuccess: (result) => {
            queryClient.setQueryData(PASSWORD_RESET_CODES_QUERY_KEY, {
                entries: result.entries,
                clockSkewMs: result.clockSkewMs,
            });
        },
    });
}
