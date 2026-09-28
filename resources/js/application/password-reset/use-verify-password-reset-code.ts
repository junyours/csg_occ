import { useMutation } from '@tanstack/react-query';

import { httpPasswordResetRepository } from '@/infrastructure/password-reset/password-reset.repository.http';

export function useVerifyPasswordResetCode() {
    return useMutation({
        mutationFn: ({ studentNumber, code }: { studentNumber: string; code: string }) =>
            httpPasswordResetRepository.verifyCode(studentNumber, code),
    });
}
