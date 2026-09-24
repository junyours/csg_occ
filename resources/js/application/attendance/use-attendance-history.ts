import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { httpAttendanceHistoryRepository } from '@/infrastructure/attendance/attendance-history.repository.http';
import type { AttendanceHistoryFilters } from '@/infrastructure/attendance/attendance-history.repository.http';

export const ATTENDANCE_HISTORY_QUERY_KEY = ['attendance-history'];

export function attendanceHistoryQueryKey(filters: AttendanceHistoryFilters) {
    return [...ATTENDANCE_HISTORY_QUERY_KEY, filters] as const;
}

export function useAttendanceHistory(filters: AttendanceHistoryFilters) {
    return useQuery({
        queryKey: attendanceHistoryQueryKey(filters),
        queryFn: () => httpAttendanceHistoryRepository.list(filters),
        // Same reasoning as usePenalties/useStudents: keeps the current
        // page's rows on screen while a new filter/page loads instead of
        // flashing to empty on every keystroke or select change.
        placeholderData: keepPreviousData,
    });
}
