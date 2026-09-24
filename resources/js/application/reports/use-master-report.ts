import { useQuery } from '@tanstack/react-query';

import { httpReportsRepository } from '@/infrastructure/reports/reports.repository.http';

export const MASTER_REPORT_QUERY_KEY = ['reports', 'master'];

/**
 * GET /reports/master — every event in the current active academic year
 * + semester, each with its total Present/Absent/Late/Excluded. Backs
 * the Settings → Reports landing screen (SettingsPage's "Reports" row).
 */
export function useMasterReport() {
    return useQuery({
        queryKey: MASTER_REPORT_QUERY_KEY,
        queryFn: httpReportsRepository.getMasterReport,
    });
}
