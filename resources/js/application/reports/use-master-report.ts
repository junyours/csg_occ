import { useQuery } from '@tanstack/react-query';

import { httpReportsRepository } from '@/infrastructure/reports/reports.repository.http';

export const MASTER_REPORT_QUERY_KEY = ['reports', 'master'];

/**
 * GET /reports/master — every event in one semester (default: the current
 * active semester; pass a past `semesterId` to view an earlier term), each
 * with its total Present/Missed/Late/Excluded. Backs the Settings → Reports
 * landing screen (SettingsPage's "Reports" row).
 */
export function useMasterReport(semesterId: number | null = null) {
    return useQuery({
        queryKey: [...MASTER_REPORT_QUERY_KEY, semesterId],
        queryFn: () => httpReportsRepository.getMasterReport(semesterId),
    });
}
