import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useDeleteEventDay() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (eventDayId: number) => httpEventsRepository.deleteDay(eventDayId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
