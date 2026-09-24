import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpDepartmentsRepository } from '@/infrastructure/departments/departments.repository.http';
import { DEPARTMENTS_QUERY_KEY } from '@/application/departments/use-departments';
import type { UpdateDepartmentPayload } from '@/application/departments/departments.repository';

export function useUpdateDepartment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ id, payload }: { id: number; payload: UpdateDepartmentPayload }) =>
            httpDepartmentsRepository.update(id, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: DEPARTMENTS_QUERY_KEY });
        },
    });
}
