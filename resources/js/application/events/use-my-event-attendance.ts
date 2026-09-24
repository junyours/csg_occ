import { useQuery } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

/**
 * A student's own attendance across every session of one event. Kept
 * under its own query key (not EVENTS_QUERY_KEY) since it's per-caller
 * data, not the shared events list. No background polling — refetches
 * only on mount/invalidation (e.g. pull-to-refresh), not on a timer.
 */
export function useMyEventAttendance(eventId: number) {
    return useQuery({
        queryKey: ['events', eventId, 'my-attendance'],
        queryFn: () => httpEventsRepository.myAttendance(eventId),
        // > 0 (not just finite) so callers that only sometimes have a real
        // event id — e.g. the dashboard's streak card, which falls back to
        // 0 while there's no active event — don't fire a request for a
        // nonexistent /events/0/my-attendance.
        enabled: Number.isFinite(eventId) && eventId > 0,
    });
}
