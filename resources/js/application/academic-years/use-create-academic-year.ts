import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpAcademicYearsRepository } from '@/infrastructure/academic-years/academic-years.repository.http';
import { ACADEMIC_YEARS_QUERY_KEY } from '@/application/academic-years/use-academic-years';
import type { CreateAcademicYearPayload } from '@/application/academic-years/academic-years.repository';

export function useCreateAcademicYear() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateAcademicYearPayload) => httpAcademicYearsRepository.create(payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ACADEMIC_YEARS_QUERY_KEY });
        },
    });
}
