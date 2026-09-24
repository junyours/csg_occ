import { useQuery } from '@tanstack/react-query';

import { httpAcademicYearsRepository } from '@/infrastructure/academic-years/academic-years.repository.http';

export const ACADEMIC_YEARS_QUERY_KEY = ['academic-years'];

export function useAcademicYears() {
    return useQuery({
        queryKey: ACADEMIC_YEARS_QUERY_KEY,
        queryFn: httpAcademicYearsRepository.list,
    });
}

/**
 * The one academic year the rest of the app defaults to — the dashboard's
 * initial scope, and the default selection on a new-event form. `undefined`
 * while loading, `null` once loaded if no academic year is currently active.
 */
export function useActiveAcademicYear() {
    const { data: academicYears, isLoading } = useAcademicYears();

    const activeAcademicYear = isLoading ? undefined : (academicYears?.find((year) => year.isActive) ?? null);

    return { activeAcademicYear, isLoading };
}
