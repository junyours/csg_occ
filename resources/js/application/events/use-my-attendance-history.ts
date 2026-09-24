import { useQuery } from '@tanstack/react-query';

import { httpEventsRepository } from '@/infrastructure/events/events.repository.http';

/**
 * A student's own attendance across every event, flattened to one row
 * per session — powers the "Attendance History" account settings page.
 * Kept under its own query key, separate from EVENTS_QUERY_KEY and the
 * per-event my-attendance key, since it's a different shape of the same
 * underlying data.
 */
export function useMyAttendanceHistory() {
    return useQuery({
        queryKey: ['my-attendance-history'],
        queryFn: httpEventsRepository.myAttendanceHistory,
    });
}
