import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import { STUDENTS_QUERY_KEY } from '@/application/students/use-students';
import type { CreateStudentPayload } from '@/application/students/students.repository';

export function useCreateStudent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: (payload: CreateStudentPayload) => httpStudentsRepository.create(payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY });
        },
    });
}
