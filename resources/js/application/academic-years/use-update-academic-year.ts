import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpAcademicYearsRepository } from '@/infrastructure/academic-years/academic-years.repository.http';
import { ACADEMIC_YEARS_QUERY_KEY } from '@/application/academic-years/use-academic-years';
import type { UpdateAcademicYearPayload } from '@/application/academic-years/academic-years.repository';

export function useUpdateAcademicYear() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateAcademicYearPayload }) =>
            httpAcademicYearsRepository.update(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ACADEMIC_YEARS_QUERY_KEY });
        },
    });
}
