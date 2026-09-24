import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository, type CreateEventPayload } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useCreateEvent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateEventPayload) => httpEventsRepository.create(payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
