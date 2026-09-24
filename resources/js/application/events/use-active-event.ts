import { useQuery } from '@tanstack/react-query';

import { EventStatus } from '@/domain/enums';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';
import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

/**
 * The event that CSG has not yet marked "ended".
 *
 * This used to be derived from whether any session happened to be
 * `ongoing` — which meant the moment the last-open session ended (even if
 * the event still had more days/windows to go), the event looked "done"
 * and vanished from here. An event now carries its own explicit status
 * (see EventStatus / EndEvent), so a session ending never implicitly ends
 * the event — only an explicit "End Event" action does. Used to decide
 * whether the Events tab should appear in the bottom nav — only ever one
 * active event at a time, so we surface just that one.
 */
export function useActiveEvent() {
    const { data: events, isLoading } = useQuery({
        queryKey: EVENTS_QUERY_KEY,
        queryFn: httpEventsRepository.list,
    });

    const activeEvent = events?.find((event) => event.status === EventStatus.Ongoing);

    return { activeEvent, isLoading };
}
