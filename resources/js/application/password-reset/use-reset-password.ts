import { useMutation } from '@tanstack/react-query';

import {
    httpPasswordResetRepository,
    type ResetPasswordPayload,
} from '@/infrastructure/password-reset/password-reset.repository.http';

export function useResetPassword() {
    return useMutation({
        mutationFn: (payload: ResetPasswordPayload) => httpPasswordResetRepository.resetPassword(payload),
    });
}
