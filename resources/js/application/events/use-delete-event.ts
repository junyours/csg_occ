import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

// For "I created this by mistake" — refused with 409 if the event has
// ended, if any session anywhere under it has started or ended, or if
// any exclusion/report record already ties to it. See events.repository.http.ts.
export function useDeleteEvent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (eventId: number) => httpEventsRepository.delete(eventId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
