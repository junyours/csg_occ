import { useMutation } from '@tanstack/react-query';

import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { useAuthStore } from '@/application/auth/auth.store';
import type { ChangePasswordPayload } from '@/application/auth/auth.repository';

export function useChangePassword() {
    const student = useAuthStore((state) => state.student);
    const token = useAuthStore((state) => state.token);
    const setSession = useAuthStore((state) => state.set);

    return useMutation({
        mutationFn: (payload: ChangePasswordPayload) => httpAuthRepository.changePassword(payload),
        onSuccess: () => {
            if (student && token) {
                setSession(token, { ...student, mustChangePassword: false });
            }
        },
    });
}
