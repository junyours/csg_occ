import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

// System Admin override for an ended event. Everything derived from
// attendance (dashboard, penalties, reports, history) changes, so refresh
// all cached queries rather than guessing which keys are affected.
export function useForceDeleteEvent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ eventId, confirmName }: { eventId: number; confirmName: string }) =>
            httpEventsRepository.forceDelete(eventId, confirmName),
        onSuccess: () => {
            queryClient.invalidateQueries();
        },
    });
}
