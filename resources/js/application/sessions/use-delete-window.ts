import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSessionsRepository } from '@/infrastructure/sessions/sessions.repository.http';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';

export function useDeleteWindow() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ eventDayId, windowType }: { eventDayId: number; windowType: string }) =>
            httpSessionsRepository.deleteWindow(eventDayId, windowType),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: EVENTS_QUERY_KEY });
        },
    });
}
