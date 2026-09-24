import { useQuery } from '@tanstack/react-query';

import { httpSemestersRepository } from '@/infrastructure/semesters/semesters.repository.http';

export function semestersQueryKey(academicYearId: number) {
    return ['academic-years', academicYearId, 'semesters'];
}

/**
 * `academicYearId` is nullable so a caller (e.g. a collapsed per-year
 * section, or a page that hasn't resolved the active academic year yet)
 * can defer fetching until it's actually needed.
 */
export function useSemesters(academicYearId: number | null) {
    return useQuery({
        queryKey: academicYearId !== null ? semestersQueryKey(academicYearId) : ['semesters', 'idle'],
        queryFn: () => httpSemestersRepository.list(academicYearId as number),
        enabled: academicYearId !== null,
    });
}
