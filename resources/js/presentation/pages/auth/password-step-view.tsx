import type { FormEvent } from 'react';
import { ShieldAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Text } from '@/presentation/components/typography';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import type { RememberedAccount } from '@/application/auth/remembered-accounts.store';
import { cn } from '@/lib/utils';

interface PasswordStepViewProps {
    account: RememberedAccount;
    password: string;
    onPasswordChange: (password: string) => void;
    onSubmit: (event: FormEvent) => void;
    onBack: () => void;
    isSubmitting: boolean;
    isRateLimited: boolean;
    rateLimitedLabel: string;
}

export function PasswordStepView({
    account,
    password,
    onPasswordChange,
    onSubmit,
    onBack,
    isSubmitting,
    isRateLimited,
    rateLimitedLabel,
}: PasswordStepViewProps) {
    return (
        <>
            {/* Confirms who's signing in. Given a violet wash so it reads as
                context rather than as another form field. */}
            <div className={cn('mb-6 flex items-center gap-4 rounded-2xl border p-4', TONE.violet.wash)}>
                <UserAvatar student={account} className="size-11 ring-2 ring-violet-500/20" />
                <div className="min-w-0 flex-1">
                    <Text className="truncate font-medium leading-tight">
                        {account.firstName} {account.lastName}
                    </Text>
                    <Text variant="caption" className="truncate font-mono tracking-wide">
                        {account.studentNumber}
                    </Text>
                </div>
            </div>

            <form onSubmit={onSubmit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        autoFocus
                        value={password}
                        onChange={(event) => onPasswordChange(event.target.value)}
                        required
                    />
                </div>

                {/*
                  The lockout used to live only as label text on a disabled
                  button, where it competed with "Sign in" for the same few
                  words. Pulled out into its own red-washed notice, the
                  button goes back to saying what it does and the reason
                  gets room to explain itself.
                */}
                {isRateLimited && (
                    <div className={cn('flex items-start gap-4 rounded-2xl border p-4', TONE.red.wash)} role="alert">
                        <Tile tone="red" size="sm" variant="solid" Icon={ShieldAlert} />
                        <div className="min-w-0">
                            <Text variant="small" className="font-medium text-foreground">
                                {rateLimitedLabel}
                            </Text>
                            <Text variant="caption">Too many sign-in attempts from this device.</Text>
                        </div>
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={isSubmitting || isRateLimited}>
                    {isSubmitting ? 'Signing in…' : 'Sign in'}
                </Button>

                <button
                    type="button"
                    onClick={onBack}
                    className="block w-full text-center text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >
                    Not {account.firstName}? Choose another account
                </button>
            </form>
        </>
    );
}
