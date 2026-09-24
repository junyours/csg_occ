import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository, type UpdateEventDayPayload } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

// event-day-window-edit-delete-plan.md §4.2: date only, via PATCH
// /event-days/{eventDay}. A 422 on the `date` field means the new date
// collides with another day already in this event — the caller (see
// EventDaySection's onDateCollision) offers the Reschedule flow instead.
export function useUpdateEventDay() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateEventDayPayload }) =>
            httpEventsRepository.updateDay(id, payload),
        onSuccess: () => {
            // Days live nested inside the /events response, so the whole
            // list is invalidated rather than trying to patch one day in
            // the cache by hand.
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
