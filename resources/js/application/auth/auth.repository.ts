import type { AuthenticatedUser } from '@/domain/entities';


export interface LoginCredentials {
    username: string;
    password: string;
}

export interface ChangePasswordPayload {
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
}

/**
 * Port the application layer depends on. The infrastructure layer provides
 * the concrete implementation (auth.repository.http.ts) that actually talks
 * to Laravel Sanctum over HTTP. Swapping transport (e.g. for tests, or a
 * mock backend) only ever means swapping this implementation.
 */
export interface AuthRepository {
    login(credentials: LoginCredentials): Promise<AuthenticatedUser>;
    changePassword(payload: ChangePasswordPayload): Promise<void>;
    acceptTerms(): Promise<void>;
    logout(): Promise<void>;
}
