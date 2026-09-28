import { useMutation } from '@tanstack/react-query';

import { httpStudentsRepository } from '@/infrastructure/students/students.repository.http';

export function useResetStudentPassword() {
    return useMutation({
        mutationFn: (studentId: number) => httpStudentsRepository.resetPassword(studentId),
    });
}
