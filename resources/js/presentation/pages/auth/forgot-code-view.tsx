import type { FormEvent } from 'react';
import { KeyRound, ShieldAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useNow } from '@/application/shared/use-now';
import {
    PASSWORD_RESET_CODE_LENGTH,
    formatCountdown,
    sanitizeCodeInput,
    secondsRemaining,
} from '@/domain/password-reset';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { cn } from '@/lib/utils';

interface ForgotCodeViewProps {
    studentNumber: string;
    code: string;
    onCodeChange: (code: string) => void;
    onSubmit: (event: FormEvent) => void;
    isVerifying: boolean;
    /** Epoch ms — when "request a new code" opens up again. */
    resendAt: number;
    /** Epoch ms — verifying is blocked until then (too many wrong tries), or null. */
    lockedUntil: number | null;
    onResend: () => void;
    isResending: boolean;
    /** Back to the Student ID step — allowed only because nothing has been verified yet. */
    onChangeStudent: () => void;
    onBackToLogin: () => void;
}

export function ForgotCodeView({
    studentNumber,
    code,
    onCodeChange,
    onSubmit,
    isVerifying,
    resendAt,
    lockedUntil,
    onResend,
    isResending,
    onChangeStudent,
    onBackToLogin,
}: ForgotCodeViewProps) {
    // The only piece of the flow that shows a clock, so it's the only piece
    // that re-renders every second.
    const now = useNow(1000);
    const resendSeconds = secondsRemaining(resendAt, now);
    const lockedSeconds = lockedUntil === null ? 0 : secondsRemaining(lockedUntil, now);
    const isLocked = lockedSeconds > 0;
    const canVerify = code.length === PASSWORD_RESET_CODE_LENGTH && !isVerifying && !isLocked;

    return (
        <div className="space-y-4">
            <div className={cn('flex items-center gap-4 rounded-2xl border p-4', TONE.violet.wash)}>
                <Tile tone="violet" size="sm" variant="solid" Icon={KeyRound} />
                <div className="min-w-0 flex-1">
                    <Text variant="caption">Code requested for</Text>
                    <Text className="truncate font-mono font-medium tracking-wide">{studentNumber}</Text>
                </div>
                <button
                    type="button"
                    onClick={onChangeStudent}
                    className="shrink-0 text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Change
                </button>
            </div>

            <Text variant="small">
                Ask your SC or CSG officer for your 6-digit code and type it below. It works once, and it expires 5
                minutes after it was requested.
            </Text>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="resetCode">6-digit code</Label>
                    <Input
                        id="resetCode"
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        autoFocus
                        maxLength={PASSWORD_RESET_CODE_LENGTH}
                        placeholder="000000"
                        value={code}
                        onChange={(event) => onCodeChange(sanitizeCodeInput(event.target.value))}
                        disabled={isLocked}
                        className="h-12 text-center font-mono text-2xl tracking-[0.5em] md:text-2xl"
                    />
                </div>

                {isLocked && (
                    <div className={cn('flex items-start gap-4 rounded-2xl border p-4', TONE.red.wash)} role="alert">
                        <Tile tone="red" size="sm" variant="solid" Icon={ShieldAlert} />
                        <div className="min-w-0">
                            <Text variant="small" className="font-medium text-foreground">
                                Too many attempts. Try again later
                            </Text>
                            <Text variant="caption">
                                This code is locked. You can request a new one in {formatCountdown(lockedSeconds)}.
                            </Text>
                        </div>
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={!canVerify}>
                    {isVerifying ? 'Checking…' : 'Verify code'}
                </Button>
            </form>

            <div className="flex flex-col items-center gap-2 text-center">
                {resendSeconds > 0 ? (
                    <Text variant="caption">
                        You can&apos;t request a new code while this one is unused. It expires in{' '}
                        {formatCountdown(resendSeconds)} — then you can request another.
                    </Text>
                ) : (
                    <button
                        type="button"
                        onClick={onResend}
                        disabled={isResending}
                        className="text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none disabled:opacity-50"
                    >
                        {isResending ? 'Requesting…' : 'Request a new code'}
                    </button>
                )}
                <button
                    type="button"
                    onClick={onBackToLogin}
                    className="text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Back to sign in
                </button>
            </div>
        </div>
    );
}
