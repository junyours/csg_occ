import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

// Ending an event cascades to force-end every still-ongoing session under
// it (see EndEvent on the backend), so invalidating the events list here
// picks up both the event's own new status and every affected session's.
export function useEndEvent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (eventId: number) => httpEventsRepository.end(eventId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
