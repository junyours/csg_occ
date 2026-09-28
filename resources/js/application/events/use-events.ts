import { useQuery } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

// Same key use-active-event.ts polls under — sharing it means creating an
// event from this page and the bottom-nav's active-event check both read
// from (and invalidate) one cache entry instead of two. A specific semester
// appends to the key, so invalidating ['events'] still refreshes every variant.
export const EVENTS_QUERY_KEY = ['events'];

/** `semester`: null/omitted = current semester; a number = that semester; 'all' = every semester (admins). */
export function useEvents(semester: number | 'all' | null = null) {
    return useQuery({
        queryKey: semester === null ? EVENTS_QUERY_KEY : [...EVENTS_QUERY_KEY, semester],
        // Wrapped: react-query passes a context object as the first argument,
        // which must never be mistaken for the semester parameter.
        queryFn: () => httpEventsRepository.list(semester),
    });
}
