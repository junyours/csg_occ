import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpSemestersRepository } from '@/infrastructure/semesters/semesters.repository.http';
import { semestersQueryKey } from '@/application/semesters/use-semesters';
import type { UpdateSemesterPayload } from '@/application/semesters/semesters.repository';

export function useUpdateSemester(academicYearId: number) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateSemesterPayload }) =>
            httpSemestersRepository.update(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: semestersQueryKey(academicYearId) });
        },
    });
}
