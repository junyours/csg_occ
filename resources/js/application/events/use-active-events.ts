import { useQuery } from '@tanstack/react-query';

import { EventStatus } from '@/domain/enums';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';
import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

/**
 * Every event CSG hasn't ended yet — plural, unlike useActiveEvent (which
 * assumes there's only ever one "the" active event and is used to decide
 * whether the bottom-nav Events tab should appear). Nothing stops more
 * than one event being open at once — two overlapping intramurals, or one
 * CSG Admin simply forgetting to end last week's event before starting a
 * new one — so anything that needs to show *all* of a student's currently
 * open events (like the dashboard's event schedule list) should read from
 * here instead of grabbing only the first match. The attendance streak
 * itself is no longer per-event — see MyAttendanceStreak, which reads a
 * single global cross-event streak from the dashboard summary instead.
 */
export function useActiveEvents() {
    const { data: events, isLoading } = useQuery({
        queryKey: EVENTS_QUERY_KEY,
        queryFn: httpEventsRepository.list,
    });

    const activeEvents = (events ?? []).filter((event) => event.status === EventStatus.Ongoing);

    return { activeEvents, isLoading };
}
