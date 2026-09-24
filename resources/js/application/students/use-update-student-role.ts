import { useMutation, useQueryClient } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';
import { STUDENTS_QUERY_KEY } from '@/application/students/use-students';
import type { UpdateStudentRolePayload } from '@/application/students/students.repository';

export function useUpdateStudentRole() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({ studentId, payload }: { studentId: number; payload: UpdateStudentRolePayload }) =>
            httpStudentsRepository.updateRole(studentId, payload),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: STUDENTS_QUERY_KEY });
        },
    });
}
