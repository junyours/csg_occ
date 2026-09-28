import { useMutation } from '@tanstack/react-query';

import { httpPasswordResetRepository } from '@/infrastructure/password-reset/password-reset.repository.http';

export function useRequestPasswordResetCode() {
    return useMutation({
        mutationFn: (studentNumber: string) => httpPasswordResetRepository.requestCode(studentNumber),
    });
}
