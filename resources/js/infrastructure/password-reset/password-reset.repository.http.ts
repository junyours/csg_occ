import { httpClient } from '@/infrastructure/http/client';
import type { Role } from '@/domain/enums';
import type { PasswordResetCodeStatus } from '@/domain/password-reset';

/**
 * One row on the admin's Password Reset Codes screen (GET
 * /password-reset-codes). `code` is only ever present while the code is
 * still usable — the server never sends a spent, locked or expired one.
 */
export interface PasswordResetCodeEntry {
    id: number;
    studentId: number;
    studentNumber: string;
    firstName: string;
    lastName: string;
    role: Role;
    departmentCode: string | null;
    photoUrl: string | null;
    status: PasswordResetCodeStatus;
    code: string | null;
    attempts: number;
    createdAt: string;
    expiresAt: string;
}

export interface PasswordResetCodeList {
    entries: PasswordResetCodeEntry[];
    /**
     * Server clock minus this browser's clock, in ms, measured when the
     * response arrived. Add it to Date.now() to get "server now" — the
     * countdowns must agree with the server about when a code expires, not
     * with whatever this device's clock happens to say.
     */
    clockSkewMs: number;
}

interface RawEntry {
    id: number;
    student_id: number;
    student_number: string;
    first_name: string;
    last_name: string;
    role: Role;
    department_code: string | null;
    photo_url: string | null;
    status: PasswordResetCodeStatus;
    code: string | null;
    attempts: number;
    created_at: string;
    expires_at: string;
}

function toEntry(raw: RawEntry): PasswordResetCodeEntry {
    return {
        id: raw.id,
        studentId: raw.student_id,
        studentNumber: raw.student_number,
        firstName: raw.first_name,
        lastName: raw.last_name,
        role: raw.role,
        departmentCode: raw.department_code,
        photoUrl: raw.photo_url,
        status: raw.status,
        code: raw.code,
        attempts: raw.attempts,
        createdAt: raw.created_at,
        expiresAt: raw.expires_at,
    };
}

interface RawList {
    now: string;
    data: RawEntry[];
}

function toList(raw: RawList, receivedAt: number): PasswordResetCodeList {
    return {
        entries: raw.data.map(toEntry),
        clockSkewMs: Date.parse(raw.now) - receivedAt,
    };
}

export interface ResetPasswordPayload {
    studentNumber: string;
    resetToken: string;
    newPassword: string;
    newPasswordConfirmation: string;
}

export const httpPasswordResetRepository = {
    // ---- Signed-out "forgot password" flow ---------------------------------

    /**
     * Asks for a code. Answers the same way whether or not the Student ID
     * exists. `retryAfterSeconds` is when another code may be requested;
     * `alreadyActive` means nothing new was issued because an earlier
     * request is still in effect (its code is unused and unexpired).
     */
    async requestCode(studentNumber: string): Promise<{ retryAfterSeconds: number; alreadyActive: boolean }> {
        const { data } = await httpClient.post<{ retry_after: number; already_active?: boolean }>(
            '/auth/forgot-password',
            { student_number: studentNumber },
        );
        return { retryAfterSeconds: data.retry_after, alreadyActive: data.already_active === true };
    },

    /** Spends the code; the returned token authorizes exactly one password change. */
    async verifyCode(studentNumber: string, code: string): Promise<{ resetToken: string }> {
        const { data } = await httpClient.post<{ reset_token: string }>('/auth/forgot-password/verify', {
            student_number: studentNumber,
            code,
        });
        return { resetToken: data.reset_token };
    },

    async resetPassword(payload: ResetPasswordPayload): Promise<void> {
        await httpClient.post('/auth/forgot-password/reset', {
            student_number: payload.studentNumber,
            reset_token: payload.resetToken,
            new_password: payload.newPassword,
            new_password_confirmation: payload.newPasswordConfirmation,
        });
    },

    // ---- Admin screen -------------------------------------------------------

    /** One request. Called when the admin screen opens and when Refresh is pressed — never on a timer. */
    async listCodes(): Promise<PasswordResetCodeList> {
        const receivedAt = Date.now();
        const { data } = await httpClient.get<RawList>('/password-reset-codes');
        return toList(data, receivedAt);
    },

    /**
     * "Clear used & expired". The server answers with the refreshed list
     * (and how many rows it removed), so no follow-up GET is needed.
     */
    async clearDeadCodes(): Promise<PasswordResetCodeList & { deleted: number }> {
        const receivedAt = Date.now();
        const { data } = await httpClient.delete<RawList & { deleted: number }>('/password-reset-codes');
        return { ...toList(data, receivedAt), deleted: data.deleted };
    },
};
