import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';
import { recentScansQueryKey } from '@/application/sessions/use-recent-scans';

/**
 * Undo one scan in a session. The officer's escape hatch for the case the
 * scanner can't catch on its own: a valid QR presented by someone who
 * isn't its owner. The record is deleted server-side, the student returns
 * to pending, and the badge's real owner can scan in normally.
 *
 * Invalidates the session's recent-scans list on success so the row
 * disappears from the strip it was reversed from — the list is the
 * server's, not this device's, so it's refetched rather than spliced.
 */
export function useReverseScan(sessionId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ recordId, reason }: { recordId: number; reason?: string }) =>
            httpSessionsRepository.reverseScan(sessionId, recordId, reason),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: recentScansQueryKey(sessionId) });
        },
    });
}
