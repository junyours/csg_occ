import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';
import { recentScansQueryKey } from '@/application/sessions/use-recent-scans';

/**
 * One scan attempt against a fixed session.
 *
 * The scanning screen used to build its own "recent scans" list out of
 * these mutation results. It doesn't anymore — that list lives on the
 * server now (see use-recent-scans), because a device-local one can't
 * account for a scan being reversed and quietly mixed in rows from other
 * sessions. So the only cache work here is invalidating that list for
 * *this* session; nothing else in the app shows live per-scan data.
 */
export function useScanAttendance(sessionId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (token: string) => httpSessionsRepository.scan(sessionId, token),
        onSuccess: () => {
            // Fires for a duplicate scan too. That's intentional: a
            // duplicate means the row is already on the board, and a
            // cheap refetch is a smaller price than reasoning about
            // which outcomes can and can't have changed the list.
            queryClient.invalidateQueries({ queryKey: recentScansQueryKey(sessionId) });
        },
    });
}
