import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository, type RescheduleTarget } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useRescheduleEventDays() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ eventId, targets }: { eventId: number; targets: RescheduleTarget[] }) =>
            httpEventsRepository.reschedule(eventId, targets),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
