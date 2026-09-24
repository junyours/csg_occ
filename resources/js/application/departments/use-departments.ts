import { useQuery } from '@tanstack/react-query';

import { httpDepartmentsRepository } from '@/infrastructure/departments/departments.repository.http';

export const DEPARTMENTS_QUERY_KEY = ['departments'];

export function useDepartments() {
    return useQuery({
        queryKey: DEPARTMENTS_QUERY_KEY,
        queryFn: httpDepartmentsRepository.list,
    });
}
