import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSemestersRepository } from '@/infrastructure/semesters/semesters.repository.http';
import { semestersQueryKey } from '@/application/semesters/use-semesters';

/**
 * One hook for both directions — mirrors use-set-academic-year-active.ts.
 */
export function useSetSemesterActive(academicYearId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, active }: { id: number; active: boolean }) =>
            active ? httpSemestersRepository.activate(id) : httpSemestersRepository.deactivate(id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: semestersQueryKey(academicYearId) });
        },
    });
}
