import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import { STUDENTS_QUERY_KEY } from '@/application/students/use-students';
import type { UpdateStudentPayload } from '@/application/students/students.repository';
import type { StudentStatus } from '@/domain/enums';

export function useUpdateStudent() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ studentId, payload }: { studentId: number; payload: UpdateStudentPayload }) =>
            httpStudentsRepository.update(studentId, payload),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY }),
    });
}

export function useUpdateStudentStatus() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ studentId, status }: { studentId: number; status: StudentStatus }) =>
            httpStudentsRepository.updateStatus(studentId, status),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY }),
    });
}
