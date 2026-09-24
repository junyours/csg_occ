import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import { STUDENTS_QUERY_KEY } from '@/application/students/use-students';
import type { Student } from '@/domain/entities';

export function useUpdateStudentPhoto() {
    const queryClient = useQueryClient();

    return useMutation<Student, unknown, { studentId: number; file: File | Blob }>({
        mutationFn: ({ studentId, file }) => httpStudentsRepository.updatePhoto(studentId, file),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY });
        },
    });
}
