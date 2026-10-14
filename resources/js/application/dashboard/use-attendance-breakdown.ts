import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { DASHBOARD_QUERY_KEY } from '@/application/dashboard/use-dashboard';
import {
    httpDashboardRepository,
    type AttendanceBreakdownParams,
} from '@/infrastructure/dashboard/dashboard.repository.http';

/**
 * The admin dashboard's attendance cards for one event / one day. Lives
 * under the ['dashboard'] key so the pull-to-refresh gesture refreshes it
 * with everything else; keepPreviousData holds the old numbers on screen
 * while a new filter loads instead of flashing a skeleton on every tap.
 */
export function useAttendanceBreakdown(params: AttendanceBreakdownParams, enabled = true) {
    return useQuery({
        queryKey: [
            ...DASHBOARD_QUERY_KEY,
            'attendance-breakdown',
            params.eventId ?? null,
            params.eventId ? (params.eventDayId ?? null) : null,
            params.eventId ? null : (params.semester ?? null),
        ],
        queryFn: () => httpDashboardRepository.attendanceBreakdown(params),
        placeholderData: keepPreviousData,
        enabled,
    });
}
