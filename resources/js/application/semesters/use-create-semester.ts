import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSemestersRepository } from '@/infrastructure/semesters/semesters.repository.http';
import { semestersQueryKey } from '@/application/semesters/use-semesters';
import type { CreateSemesterPayload } from '@/application/semesters/semesters.repository';

export function useCreateSemester(academicYearId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateSemesterPayload) => httpSemestersRepository.create(academicYearId, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: semestersQueryKey(academicYearId) });
        },
    });
}
