import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Text } from '@/presentation/components/typography';
import { formatStudentNumber, LOGIN_STUDENT_NUMBER_MAX_DIGITS } from '@/lib/utils';

interface ForgotRequestViewProps {
    studentNumber: string;
    onStudentNumberChange: (studentNumber: string) => void;
    onSubmit: (event: FormEvent) => void;
    isSubmitting: boolean;
    /** Skips asking for a new code — for someone who already has one from an officer. */
    onHaveCode: () => void;
    onBackToLogin: () => void;
}

export function ForgotRequestView({
    studentNumber,
    onStudentNumberChange,
    onSubmit,
    isSubmitting,
    onHaveCode,
    onBackToLogin,
}: ForgotRequestViewProps) {
    const hasStudentNumber = studentNumber.trim().length > 0;

    return (
        <form onSubmit={onSubmit} className="space-y-4">
            <div className="space-y-2">
                <Label htmlFor="forgotStudentNumber">Student ID Number</Label>
                <Input
                    id="forgotStudentNumber"
                    autoComplete="username"
                    inputMode="numeric"
                    autoFocus
                    placeholder="e.g. 2023-1-05413"
                    value={studentNumber}
                    onChange={(event) => onStudentNumberChange(formatStudentNumber(event.target.value, LOGIN_STUDENT_NUMBER_MAX_DIGITS))}
                    required
                />
            </div>

            <div className="rounded-2xl bg-muted p-4">
                <Text variant="caption" className="text-center">
                    We don&apos;t send codes by email or text. After you tap Get code, ask your SC or CSG officer for
                    your 6-digit code — bring your school ID.
                </Text>
            </div>

            <Button type="submit" className="w-full" disabled={!hasStudentNumber || isSubmitting}>
                {isSubmitting ? 'Requesting…' : 'Get code'}
            </Button>

            <div className="flex flex-col items-center gap-2">
                <button
                    type="button"
                    onClick={onHaveCode}
                    disabled={!hasStudentNumber}
                    className="text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none disabled:opacity-50"
                >
                    I already have a code
                </button>
                <button
                    type="button"
                    onClick={onBackToLogin}
                    className="text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Back to sign in
                </button>
            </div>
        </form>
    );
}
