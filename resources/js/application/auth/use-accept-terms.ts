import { useMutation } from '@tanstack/react-query';

import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { useAuthStore } from '@/application/auth/auth.store';

export function useAcceptTerms() {
    const student = useAuthStore((state) => state.student);
    const token = useAuthStore((state) => state.token);
    const setSession = useAuthStore((state) => state.set);

    return useMutation({
        mutationFn: () => httpAuthRepository.acceptTerms(),
        onSuccess: () => {
            if (student && token) {
                setSession(token, { ...student, hasAcceptedTerms: true });
            }
        },
    });
}
