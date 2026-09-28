import { type FormEvent, useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { CheckCircle2 } from 'lucide-react';
import { toast } from 'sonner';

import { useForgotPasswordStore } from '@/application/password-reset/forgot-password.store';
import { parsePasswordResetError } from '@/application/password-reset/password-reset-error';
import { useRequestPasswordResetCode } from '@/application/password-reset/use-request-password-reset-code';
import { useResetPassword } from '@/application/password-reset/use-reset-password';
import { useVerifyPasswordResetCode } from '@/application/password-reset/use-verify-password-reset-code';
import { PASSWORD_RESET_CODE_LENGTH, PASSWORD_RESET_TTL_SECONDS, formatCountdown } from '@/domain/password-reset';
import { cn } from '@/lib/utils';
import { ResetPasswordDialog } from '@/presentation/components/auth/reset-password-dialog';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { Text } from '@/presentation/components/typography';
import { AuthLayout } from '@/presentation/layouts/auth-layout';
import { ForgotCodeView } from '@/presentation/pages/auth/forgot-code-view';
import { ForgotRequestView } from '@/presentation/pages/auth/forgot-request-view';

/**
 * Forgot password, in three steps on one route:
 *
 *   request → the student gives their Student ID; a code is generated for an
 *             SC/CSG/System admin to read out to them.
 *   code    → they type that code. It is spent the moment it's right.
 *   reset   → a blocking dialog for the new password.
 *
 * Once a code has been accepted there is deliberately no way back to the
 * "code" step: the code is spent server-side, and the reset token that
 * replaces it lives only in this component's memory. Leaving (Cancel, the
 * browser Back button) sends the student to sign-in; a refresh starts over.
 */
type Step = 'request' | 'code' | 'reset';

export function ForgotPasswordPage() {
    const navigate = useNavigate();
    const location = useLocation();

    const pending = useForgotPasswordStore((state) => state.pending);
    const startPending = useForgotPasswordStore((state) => state.start);
    const lockPending = useForgotPasswordStore((state) => state.lock);
    const clearPending = useForgotPasswordStore((state) => state.clear);

    const requestCode = useRequestPasswordResetCode();
    const verifyCode = useVerifyPasswordResetCode();
    const resetPassword = useResetPassword();

    // Where to begin is decided once, on mount. A student who asked for a
    // code a moment ago (and refreshed, or came back from finding an
    // officer) resumes at the code step; anyone else starts from the top,
    // pre-filled with the Student ID the login screen handed over, if any.
    const [initial] = useState<{ step: Step; studentNumber: string }>(() => {
        const handedOver = (location.state as { studentNumber?: string } | null)?.studentNumber;
        const resumable =
            pending !== null && pending.resendAt > Date.now() && (!handedOver || handedOver === pending.studentNumber);

        if (resumable && pending) {
            return { step: 'code', studentNumber: pending.studentNumber };
        }
        return { step: 'request', studentNumber: handedOver ?? pending?.studentNumber ?? '' };
    });

    const [step, setStep] = useState<Step>(initial.step);
    const [studentNumber, setStudentNumber] = useState(initial.studentNumber);
    const [code, setCode] = useState('');
    // Memory only — never persisted. See the note on the component.
    const [resetToken, setResetToken] = useState<string | null>(null);

    // Browser Back from the password step goes to sign-in, never to the code
    // screen. (The code is spent anyway; this just makes the UI say so.)
    useEffect(() => {
        if (step !== 'reset') return;

        function handlePopState() {
            setResetToken(null);
            navigate('/login', { replace: true });
        }

        window.addEventListener('popstate', handlePopState);
        return () => window.removeEventListener('popstate', handlePopState);
    }, [step, navigate]);

    function goToLogin() {
        clearPending();
        setResetToken(null);
        navigate('/login', { replace: true });
    }

    function handleRequestError(error: unknown) {
        const info = parsePasswordResetError(error);

        if (info.status === 429) {
            toast.error('Too many requests. Please wait a minute and try again.');
            return;
        }

        toast.error(info.validationMessage ?? 'Could not request a code. Please try again.');
    }

    // A code can't be replaced while it is still waiting to be used. Say so
    // plainly, instead of pretending a new one was made.
    function notifyAlreadyActive(retryAfterSeconds: number) {
        toast.info("You can't request a new code yet.", {
            description: `Your last code hasn't been used or expired. Ask your SC or CSG officer for it, or request a new one in ${formatCountdown(Math.min(retryAfterSeconds, PASSWORD_RESET_TTL_SECONDS))}.`,
            duration: 8000,
        });
    }

    function handleRequestSubmit(event: FormEvent) {
        event.preventDefault();
        const number = studentNumber.trim();
        if (!number) return;

        requestCode.mutate(number, {
            onSuccess: ({ retryAfterSeconds, alreadyActive }) => {
                startPending(number, Date.now() + retryAfterSeconds * 1000);
                setCode('');
                setStep('code');
                if (alreadyActive) notifyAlreadyActive(retryAfterSeconds);
            },
            onError: handleRequestError,
        });
    }

    // "I already have a code": no request is made, so nothing is known about
    // the cooldown. resendAt = now just means "the resend button is
    // available" — and if the server is in fact still cooling down, pressing
    // it answers with the real remaining time, which then replaces this.
    function handleHaveCode() {
        const number = studentNumber.trim();
        if (!number) return;

        startPending(number, Date.now());
        setCode('');
        setStep('code');
    }

    function handleResend() {
        requestCode.mutate(studentNumber, {
            onSuccess: ({ retryAfterSeconds, alreadyActive }) => {
                startPending(studentNumber, Date.now() + retryAfterSeconds * 1000);
                setCode('');
                if (alreadyActive) {
                    notifyAlreadyActive(retryAfterSeconds);
                    return;
                }
                toast.success('New code requested. Ask your SC or CSG officer for it.');
            },
            onError: handleRequestError,
        });
    }

    function handleChangeStudent() {
        clearPending();
        setCode('');
        setStep('request');
    }

    function handleVerifySubmit(event: FormEvent) {
        event.preventDefault();
        if (code.length !== PASSWORD_RESET_CODE_LENGTH) return;

        verifyCode.mutate(
            { studentNumber, code },
            {
                onSuccess: ({ resetToken: token }) => {
                    // Spent. Nothing left to resume, so forget the pending state.
                    clearPending();
                    setResetToken(token);
                    setStep('reset');
                },
                onError: (error) => {
                    const info = parsePasswordResetError(error);
                    setCode('');

                    // Too many wrong tries (or the HTTP throttle): shown as
                    // a lockout notice with a countdown, not a toast, since
                    // it stays true long after a toast would have faded.
                    if (info.status === 429) {
                        lockPending(Date.now() + (info.retryAfterSeconds ?? 60) * 1000);
                        return;
                    }

                    if (info.attemptsRemaining !== null) {
                        toast.error(
                            `Incorrect code. ${info.attemptsRemaining} ${info.attemptsRemaining === 1 ? 'try' : 'tries'} left.`,
                        );
                        return;
                    }

                    toast.error('That code is incorrect or has expired. Ask your officer to check it, or request a new one.');
                },
            },
        );
    }

    function handleResetSubmit(newPassword: string, confirmation: string) {
        if (!resetToken) return;

        resetPassword.mutate(
            { studentNumber, resetToken, newPassword, newPasswordConfirmation: confirmation },
            {
                onSuccess: () => {
                    toast.success('Password changed. Sign in with your new password.');
                    goToLogin();
                },
                onError: (error) => {
                    const info = parsePasswordResetError(error);

                    // 410: the reset session ran out (or was replaced by a
                    // newer code). There's nothing to retry with — start over.
                    if (info.status === 410) {
                        toast.error('Your reset session expired. Please start over.');
                        goToLogin();
                        return;
                    }

                    toast.error(
                        info.validationMessage ?? info.message ?? 'Could not change your password. Please try again.',
                    );
                },
            },
        );
    }

    if (step === 'reset') {
        return (
            <AuthLayout title="Code accepted" description="Choose a new password to finish.">
                <div className={cn('flex items-start gap-4 rounded-2xl border p-4', TONE.emerald.wash)}>
                    <Tile tone="emerald" size="sm" variant="solid" Icon={CheckCircle2} />
                    <div className="min-w-0">
                        <Text variant="small" className="font-medium text-foreground">
                            Your code was verified
                        </Text>
                        <Text variant="caption">It can&apos;t be used again. Set your new password to continue.</Text>
                    </div>
                </div>

                <ResetPasswordDialog
                    open
                    isSubmitting={resetPassword.isPending}
                    onSubmit={handleResetSubmit}
                    onCancel={goToLogin}
                />
            </AuthLayout>
        );
    }

    if (step === 'code') {
        return (
            <AuthLayout title="Enter your code" description="Get the 6-digit code from your SC or CSG officer.">
                <ForgotCodeView
                    studentNumber={studentNumber}
                    code={code}
                    onCodeChange={setCode}
                    onSubmit={handleVerifySubmit}
                    isVerifying={verifyCode.isPending}
                    resendAt={pending?.resendAt ?? 0}
                    lockedUntil={pending?.lockedUntil ?? null}
                    onResend={handleResend}
                    isResending={requestCode.isPending}
                    onChangeStudent={handleChangeStudent}
                    onBackToLogin={goToLogin}
                />
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Forgot password?" description="Enter your Student ID Number to get a reset code.">
            <ForgotRequestView
                studentNumber={studentNumber}
                onStudentNumberChange={setStudentNumber}
                onSubmit={handleRequestSubmit}
                isSubmitting={requestCode.isPending}
                onHaveCode={handleHaveCode}
                onBackToLogin={goToLogin}
            />
        </AuthLayout>
    );
}
