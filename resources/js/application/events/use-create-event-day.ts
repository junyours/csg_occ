import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository, type CreateEventDayPayload } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useCreateEventDay(eventId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateEventDayPayload) => httpEventsRepository.createDay(eventId, payload),
        onSuccess: () => {
            // Days live nested inside the /events response, not their own
            // endpoint — refetching the events list is what pulls the new
            // day (and its empty sessions array) back into view.
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
