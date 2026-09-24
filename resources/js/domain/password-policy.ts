/**
 * Client-side mirror of the server-side rule in
 * App\Http\Requests\Auth\ChangePasswordRequest
 * (Password::min(8)->mixedCase()->numbers()->symbols()).
 *
 * This only exists to give the user live, per-keystroke feedback before a
 * request is ever sent — the backend rule is still the source of truth
 * and re-validates everything server-side (never trust the client alone).
 */

export type PasswordRequirementId = 'length' | 'uppercase' | 'lowercase' | 'number' | 'symbol';

export interface PasswordRequirement {
    id: PasswordRequirementId;
    label: string;
    test: (password: string) => boolean;
}

export const PASSWORD_REQUIREMENTS: PasswordRequirement[] = [
    { id: 'length', label: 'At least 8 characters', test: (password) => password.length >= 8 },
    { id: 'uppercase', label: 'One uppercase letter (A–Z)', test: (password) => /[A-Z]/.test(password) },
    { id: 'lowercase', label: 'One lowercase letter (a–z)', test: (password) => /[a-z]/.test(password) },
    { id: 'number', label: 'One number (0–9)', test: (password) => /[0-9]/.test(password) },
    { id: 'symbol', label: 'One symbol (!@#$%…)', test: (password) => /[^A-Za-z0-9]/.test(password) },
];

export interface PasswordRequirementResult {
    id: PasswordRequirementId;
    label: string;
    met: boolean;
}

export interface PasswordPolicyEvaluation {
    results: PasswordRequirementResult[];
    metCount: number;
    total: number;
    /** 0–100, for a progress bar. */
    score: number;
    isValid: boolean;
}

export function evaluatePasswordPolicy(password: string): PasswordPolicyEvaluation {
    const results = PASSWORD_REQUIREMENTS.map((requirement) => ({
        id: requirement.id,
        label: requirement.label,
        met: requirement.test(password),
    }));

    const metCount = results.filter((result) => result.met).length;
    const total = results.length;

    return {
        results,
        metCount,
        total,
        score: Math.round((metCount / total) * 100),
        isValid: metCount === total,
    };
}

export type PasswordStrengthLabel = 'Very weak' | 'Weak' | 'Fair' | 'Good' | 'Strong';

export function passwordStrengthLabel(metCount: number, total: number): PasswordStrengthLabel {
    const ratio = metCount / total;
    if (ratio <= 0.2) return 'Very weak';
    if (ratio <= 0.4) return 'Weak';
    if (ratio <= 0.6) return 'Fair';
    if (ratio <= 0.8) return 'Good';
    return 'Strong';
}

/** Tailwind class for the Progress indicator, so the bar's color moves with strength. */
export function passwordStrengthBarClass(metCount: number, total: number): string {
    const ratio = metCount / total;
    if (ratio <= 0.4) return 'bg-destructive';
    if (ratio <= 0.6) return 'bg-amber-500';
    if (ratio <= 0.8) return 'bg-amber-400';
    return 'bg-emerald-500';
}
