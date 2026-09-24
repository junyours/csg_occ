import { useMutation, useQueryClient } from '@tanstack/react-query';

import {
    httpSessionsRepository,
    type CreateSessionPayload,
} from '@/infrastructure/sessions/sessions.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useCreateSession(eventDayId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateSessionPayload) => httpSessionsRepository.createSession(eventDayId, payload),
        onSuccess: () => {
            // Sessions live nested inside the /events response too — same
            // reasoning as use-create-event-day.ts.
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
