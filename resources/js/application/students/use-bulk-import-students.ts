import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import { STUDENTS_QUERY_KEY } from '@/application/students/use-students';

export function useBulkImportStudents() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (files: File[]) => httpStudentsRepository.bulkImport(files),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY });
        },
    });
}
