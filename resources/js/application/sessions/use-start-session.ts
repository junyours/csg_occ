import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useStartSession() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (sessionId: number) => httpSessionsRepository.startSession(sessionId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
