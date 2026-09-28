import { isAxiosError } from 'axios';

export interface PasswordResetErrorInfo {
    /** HTTP status, or null when there was no response at all (offline, etc.). */
    status: number | null;
    /** The backend's own message, if it sent one. */
    message: string | null;
    /** Set on a wrong guess against a live code: how many tries are left. */
    attemptsRemaining: number | null;
    /** Set on a 429: seconds until trying again is worthwhile. */
    retryAfterSeconds: number | null;
    /** First Laravel validation message (e.g. a too-weak password), if any. */
    validationMessage: string | null;
}

/**
 * Flattens the several shapes this flow's errors come in — a locked code
 * (429, body + Retry-After header), a wrong code (422 + attempts_remaining),
 * a validation failure (422 + errors{}), an expired reset session (410) —
 * into one object the page can branch on.
 */
export function parsePasswordResetError(error: unknown): PasswordResetErrorInfo {
    const empty: PasswordResetErrorInfo = {
        status: null,
        message: null,
        attemptsRemaining: null,
        retryAfterSeconds: null,
        validationMessage: null,
    };

    if (!isAxiosError(error) || !error.response) {
        return empty;
    }

    const { status, data, headers } = error.response;
    const body = (data ?? {}) as {
        message?: unknown;
        attempts_remaining?: unknown;
        retry_after?: unknown;
        errors?: Record<string, unknown>;
    };

    const headerSeconds = Number(headers?.['retry-after']);
    const bodySeconds = Number(body.retry_after);
    const retryAfter = [bodySeconds, headerSeconds].find((value) => Number.isFinite(value) && value > 0);

    const firstErrorGroup = body.errors ? Object.values(body.errors)[0] : undefined;
    const validationMessage =
        Array.isArray(firstErrorGroup) && typeof firstErrorGroup[0] === 'string' ? firstErrorGroup[0] : null;

    return {
        status,
        message: typeof body.message === 'string' ? body.message : null,
        attemptsRemaining: typeof body.attempts_remaining === 'number' ? body.attempts_remaining : null,
        retryAfterSeconds: retryAfter ?? null,
        validationMessage,
    };
}
