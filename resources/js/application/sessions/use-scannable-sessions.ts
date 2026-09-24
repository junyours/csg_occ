import { useQuery } from '@tanstack/react-query';

import { SessionStatus } from '@/domain/enums';
import { EVENTS_QUERY_KEY } from '@/application/events/use-events';
import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

/** A session flattened out of the event/day tree, enough to label a picker. */
export interface ScannableSession {
    id: number;
    eventName: string;
    dayNumber: number;
    date: string;
    windowType: string;
    checkType: string;
}

/**
 * Every session an officer could currently scan into — i.e. every session
 * across every event whose status is "ongoing" right now. There's no
 * dedicated endpoint for this: it's derived from GET /events, which every
 * role including Officer can read (EventModelPolicy::viewAny is
 * unrestricted). Shares EVENTS_QUERY_KEY with use-events.ts/use-active-event.ts
 * (same cache entry). No background polling — pull down to refresh
 * instead of it firing on a timer.
 */
export function useScannableSessions() {
    const { data: events, isLoading } = useQuery({
        queryKey: EVENTS_QUERY_KEY,
        queryFn: httpEventsRepository.list,
    });

    const sessions: ScannableSession[] = (events ?? []).flatMap((event) =>
        event.days.flatMap((day) =>
            day.sessions
                .filter((session) => session.status === SessionStatus.Ongoing)
                .map((session) => ({
                    id: session.id,
                    eventName: event.name,
                    dayNumber: day.dayNumber,
                    date: day.date,
                    windowType: session.windowType,
                    checkType: session.checkType,
                })),
        ),
    );

    return { sessions, isLoading };
}
