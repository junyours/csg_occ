import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';
import { ATTENDANCE_HISTORY_QUERY_KEY } from '@/application/attendance/use-attendance-history';
import { DASHBOARD_QUERY_KEY } from '@/application/dashboard/use-dashboard';
import { RECENT_SCANS_QUERY_KEY } from '@/application/sessions/use-recent-scans';

/**
 * "Mark all students as Present" for one ongoing session. Writes lots of
 * attendance rows at once, so every list built from them is refetched.
 */
export function useMarkAllPresent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (sessionId: number) => httpSessionsRepository.markAllPresent(sessionId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: ATTENDANCE_HISTORY_QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: DASHBOARD_QUERY_KEY });
            queryClient.invalidateQueries({ queryKey: RECENT_SCANS_QUERY_KEY });
        },
    });
}
