/**
 * Client-side rules for the "forgot password" flow and the admin's Password
 * Reset Codes screen. Mirrors App\Models\PasswordResetCode on the backend —
 * the server is always the one that actually enforces all of this; these
 * exist so the UI can count down and format without a round-trip.
 */

export const PASSWORD_RESET_CODE_LENGTH = 6;

/** A code's lifetime, and also how long before another can be requested. */
export const PASSWORD_RESET_TTL_SECONDS = 5 * 60;

/** Mirrors App\Domain\Enums\PasswordResetCodeStatus. */
export const PasswordResetCodeStatus = {
    Pending: 'pending',
    Locked: 'locked',
    Used: 'used',
    Expired: 'expired',
} as const;
export type PasswordResetCodeStatus = (typeof PasswordResetCodeStatus)[keyof typeof PasswordResetCodeStatus];

/** Whole seconds left until `untilMs`, never negative. Rounds up, so "0:01" shows until it is truly over. */
export function secondsRemaining(untilMs: number, nowMs: number): number {
    return Math.max(0, Math.ceil((untilMs - nowMs) / 1000));
}

/** 272 → "4:32". */
export function formatCountdown(totalSeconds: number): string {
    const safe = Math.max(0, Math.floor(totalSeconds));
    const minutes = Math.floor(safe / 60);
    const seconds = safe % 60;
    return `${minutes}:${String(seconds).padStart(2, '0')}`;
}

/** Keeps digits only, capped at the code length — for the code <input>, which also receives pasted text. */
export function sanitizeCodeInput(raw: string): string {
    return raw.replace(/\D/g, '').slice(0, PASSWORD_RESET_CODE_LENGTH);
}

/** "048213" → "048 213", easier to read out loud. */
export function formatCodeForDisplay(code: string): string {
    return `${code.slice(0, 3)} ${code.slice(3)}`.trim();
}

/**
 * The status the admin's screen should *show right now*. The list is only
 * re-fetched every few seconds, so a code the server called "pending" can
 * have run out in between — that has to flip to expired on the client's own
 * clock, not wait for the next poll.
 */
export function effectiveStatus(
    status: PasswordResetCodeStatus,
    expiresAtMs: number,
    nowMs: number,
): PasswordResetCodeStatus {
    if (status !== PasswordResetCodeStatus.Pending && status !== PasswordResetCodeStatus.Locked) {
        return status;
    }
    return expiresAtMs <= nowMs ? PasswordResetCodeStatus.Expired : status;
}
