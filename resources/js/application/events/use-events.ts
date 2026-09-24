import { useQuery } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

// Same key use-active-event.ts polls under — sharing it means creating an
// event from this page and the bottom-nav's active-event check both read
// from (and invalidate) one cache entry instead of two.
export const EVENTS_QUERY_KEY = ['events'];

export function useEvents() {
    return useQuery({
        queryKey: EVENTS_QUERY_KEY,
        queryFn: httpEventsRepository.list,
    });
}
