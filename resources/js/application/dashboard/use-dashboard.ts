import { useQuery } from '@tanstack/react-query';

import { httpDashboardRepository } from '@/infrastructure/dashboard/dashboard.repository.http';

export const DASHBOARD_QUERY_KEY = ['dashboard'];

/**
 * No background polling — data is fetched once on mount and whenever the
 * caller explicitly invalidates/refetches (e.g. the pull-to-refresh
 * gesture in AppLayout). Previously auto-refetched every 15s; removed
 * per request to stop the silent recurring network calls.
 *
 * Relies on the app-wide 60s staleTime default (see app.tsx) so that
 * bouncing to another tab (e.g. the QR page) and back to the dashboard
 * reads from cache instead of silently refetching.
 */
export function useDashboard() {
    return useQuery({
        queryKey: DASHBOARD_QUERY_KEY,
        queryFn: httpDashboardRepository.summary,
    });
}
