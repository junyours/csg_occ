import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpAcademicYearsRepository } from '@/infrastructure/academic-years/academic-years.repository.http';
import { ACADEMIC_YEARS_QUERY_KEY } from '@/application/academic-years/use-academic-years';

/**
 * One hook for both directions — activating and deactivating are the same
 * shape of action (id in, updated AcademicYear out, invalidate the list),
 * just hitting a different endpoint.
 */
export function useSetAcademicYearActive() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) =>
            active ? httpAcademicYearsRepository.activate(id) : httpAcademicYearsRepository.deactivate(id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ACADEMIC_YEARS_QUERY_KEY });
        },
    });
}
