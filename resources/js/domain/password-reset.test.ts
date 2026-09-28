import { describe, expect, it } from 'vitest';

import {
    PASSWORD_RESET_CODE_LENGTH,
    PasswordResetCodeStatus,
    effectiveStatus,
    formatCodeForDisplay,
    formatCountdown,
    sanitizeCodeInput,
    secondsRemaining,
} from '@/domain/password-reset';

describe('secondsRemaining', () => {
    it('counts whole seconds down to the deadline', () => {
        expect(secondsRemaining(10_000, 0)).toBe(10);
    });

    it('rounds a partial second up so the clock never shows 0:00 early', () => {
        expect(secondsRemaining(10_001, 0)).toBe(11);
        expect(secondsRemaining(1, 0)).toBe(1);
    });

    it('never goes negative', () => {
        expect(secondsRemaining(0, 5_000)).toBe(0);
        expect(secondsRemaining(1_000, 1_000)).toBe(0);
    });
});

describe('formatCountdown', () => {
    it('formats as m:ss', () => {
        expect(formatCountdown(300)).toBe('5:00');
        expect(formatCountdown(272)).toBe('4:32');
        expect(formatCountdown(65)).toBe('1:05');
        expect(formatCountdown(9)).toBe('0:09');
        expect(formatCountdown(0)).toBe('0:00');
    });

    it('clamps negative input to zero', () => {
        expect(formatCountdown(-4)).toBe('0:00');
    });
});

describe('sanitizeCodeInput', () => {
    it('keeps digits only', () => {
        expect(sanitizeCodeInput('12a-3 4')).toBe('1234');
    });

    it('caps at the code length, e.g. after pasting a longer string', () => {
        expect(sanitizeCodeInput('123456789')).toBe('123456');
        expect(sanitizeCodeInput('123456789')).toHaveLength(PASSWORD_RESET_CODE_LENGTH);
    });

    it('keeps leading zeros', () => {
        expect(sanitizeCodeInput('004821')).toBe('004821');
    });
});

describe('formatCodeForDisplay', () => {
    it('splits into two groups of three', () => {
        expect(formatCodeForDisplay('048213')).toBe('048 213');
    });
});

describe('effectiveStatus', () => {
    const expiresAt = 1_000_000;

    it('leaves a still-live pending code alone', () => {
        expect(effectiveStatus(PasswordResetCodeStatus.Pending, expiresAt, expiresAt - 1)).toBe('pending');
    });

    it('flips a pending code to expired the moment its time is up, without waiting for a refetch', () => {
        expect(effectiveStatus(PasswordResetCodeStatus.Pending, expiresAt, expiresAt)).toBe('expired');
        expect(effectiveStatus(PasswordResetCodeStatus.Pending, expiresAt, expiresAt + 5_000)).toBe('expired');
    });

    it('also expires a locked code on time', () => {
        expect(effectiveStatus(PasswordResetCodeStatus.Locked, expiresAt, expiresAt - 1)).toBe('locked');
        expect(effectiveStatus(PasswordResetCodeStatus.Locked, expiresAt, expiresAt)).toBe('expired');
    });

    it('never rewrites a used code', () => {
        expect(effectiveStatus(PasswordResetCodeStatus.Used, expiresAt, expiresAt + 999_999)).toBe('used');
    });
});
