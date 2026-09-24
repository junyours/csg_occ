import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository, type UpdateEventPayload } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

// event-day-window-edit-delete-plan.md §4.1: name/description only, via
// PATCH /events/{event}.
export function useUpdateEvent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateEventPayload }) =>
            httpEventsRepository.update(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
