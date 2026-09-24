import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpDepartmentsRepository } from '@/infrastructure/departments/departments.repository.http';
import { DEPARTMENTS_QUERY_KEY } from '@/application/departments/use-departments';
import type { CreateDepartmentPayload } from '@/application/departments/departments.repository';

export function useCreateDepartment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateDepartmentPayload) => httpDepartmentsRepository.create(payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: DEPARTMENTS_QUERY_KEY });
        },
    });
}
