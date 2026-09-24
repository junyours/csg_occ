import { keepPreviousData, useQuery } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import type { StudentFilters } from '@/application/students/students.repository';

export const STUDENTS_QUERY_KEY = ['students'];

export function studentsQueryKey(filters: StudentFilters) {
    return [...STUDENTS_QUERY_KEY, filters] as const;
}

export function useStudents(filters: StudentFilters) {
    return useQuery({
        queryKey: studentsQueryKey(filters),
        queryFn: () => httpStudentsRepository.list(filters),
        // Keeps the current page's rows on screen while a new filter/page
        // is loading instead of flashing to an empty list every keystroke.
        placeholderData: keepPreviousData,
    });
}
