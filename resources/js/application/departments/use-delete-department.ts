import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpDepartmentsRepository } from '@/infrastructure/departments/departments.repository.http';
import { DEPARTMENTS_QUERY_KEY } from '@/application/departments/use-departments';

export function useDeleteDepartment() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (id: number) => httpDepartmentsRepository.delete(id),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: DEPARTMENTS_QUERY_KEY });
        },
    });
}
