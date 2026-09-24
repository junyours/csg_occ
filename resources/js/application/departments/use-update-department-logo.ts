import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpDepartmentsRepository } from '@/infrastructure/departments/departments.repository.http';
import { DEPARTMENTS_QUERY_KEY } from '@/application/departments/use-departments';
import type { Department } from '@/domain/entities';

export function useUpdateDepartmentLogo() {
    const queryClient = useQueryClient();

    return useMutation<Department, unknown, { departmentId: number; file: File | Blob }>({
        mutationFn: ({ departmentId, file }) => httpDepartmentsRepository.updateLogo(departmentId, file),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: DEPARTMENTS_QUERY_KEY });
        },
    });
}
