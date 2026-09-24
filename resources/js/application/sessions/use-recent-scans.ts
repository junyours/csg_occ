import { useQuery } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';

/** How many rows the scanning screen's "Recent scans" strip shows. */
export const RECENT_SCANS_LIMIT = 8;

export const RECENT_SCANS_QUERY_KEY = ['recent-scans'];

/**
 * Keyed by session id, not just by "recent scans" — two sessions open at
 * the same gate (a time-in and a time-out window) are two separate
 * lists, and switching between them must not show one session's cache
 * while the other loads.
 */
export function recentScansQueryKey(sessionId: number) {
    return [...RECENT_SCANS_QUERY_KEY, sessionId] as const;
}

/**
 * The last few students scanned into one session. Deliberately server-
 * backed (see httpSessionsRepository.recentScans): it's the list the
 * officer reverses a mis-scan from, so it has to reflect what the server
 * actually holds — including rows another officer on another phone has
 * already undone — rather than what this device happens to remember.
 *
 * No polling. It's refreshed by invalidation after a scan or a reversal
 * (see use-scan-attendance / use-reverse-scan), which is every way this
 * list can change from the scanning screen itself.
 */
export function useRecentScans(sessionId: number) {
    return useQuery({
        queryKey: recentScansQueryKey(sessionId),
        queryFn: () => httpSessionsRepository.recentScans(sessionId, RECENT_SCANS_LIMIT),
    });
}
