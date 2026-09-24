import { useQuery } from '@tanstack/react-query';

import { httpAttendanceHistoryRepository } from '@/infrastructure/attendance/attendance-history.repository.http';

export const ATTENDANCE_HISTORY_FILTER_OPTIONS_QUERY_KEY = ['attendance-history', 'filter-options'];

/**
 * Distinct major/year-level/section values for the ledger's filter
 * inputs to autosuggest against. These only change when the roster
 * changes (a new section is imported), so there's no need to refetch on
 * every keystroke — a five-minute staleTime keeps this off the network
 * for the rest of the filtering session.
 */
export function useAttendanceHistoryFilterOptions() {
    return useQuery({
        queryKey: ATTENDANCE_HISTORY_FILTER_OPTIONS_QUERY_KEY,
        queryFn: httpAttendanceHistoryRepository.filterOptions,
        staleTime: 5 * 60 * 1000,
    });
}
