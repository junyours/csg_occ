import { useMutation } from '@tanstack/react-query';

import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { useAuthStore } from '@/application/auth/auth.store';
import { useRememberedAccountsStore } from '@/application/auth/remembered-accounts.store';
import type { LoginCredentials } from '@/application/auth/auth.repository';

export function useLogin() {
    const setSession = useAuthStore((state) => state.set);
    const remember = useRememberedAccountsStore((state) => state.remember);

    return useMutation({
        mutationFn: (credentials: LoginCredentials) => httpAuthRepository.login(credentials),
        onSuccess: (result) => {
            setSession(result.token, result.student);
            // Save (or refresh) this account's card for next time — name and
            // avatar only, never the password, so it's safe to keep around
            // even after the session itself expires or the student logs out.
            remember({
                studentNumber: result.student.studentNumber,
                firstName: result.student.firstName,
                lastName: result.student.lastName,
                photoUrl: result.student.photoUrl,
                role: result.student.role,
            });
        },
    });
}
