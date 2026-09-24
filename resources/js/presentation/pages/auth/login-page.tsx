import { type FormEvent, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { toast } from 'sonner';

import { ShieldAlert } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Text } from '@/presentation/components/typography';
import { AuthLayout } from '@/presentation/layouts/auth-layout';
import { TermsGateDialog } from '@/presentation/components/legal/terms-gate-dialog';
import { AccountPickerView } from '@/presentation/pages/auth/account-picker-view';
import { PasswordStepView } from '@/presentation/pages/auth/password-step-view';
import { useAuthStore } from '@/application/auth/auth.store';
import { useRememberedAccountsStore, type RememberedAccount } from '@/application/auth/remembered-accounts.store';
import { recordLoginRateLimit, useIsRateLimited } from '@/application/auth/use-login-rate-limit';
import { useAcceptTerms } from '@/application/auth/use-accept-terms';
import { useLogin } from '@/application/auth/use-login';
import { Tile } from '@/presentation/components/tile';
import { TONE } from '@/presentation/components/tone';
import { cn, formatStudentNumber } from '@/lib/utils';
import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';

// Three things the login screen can show. "picker" is the default whenever
// there's at least one remembered account — it's the "Welcome back" card
// list (see AccountPickerView). Tapping a card moves to "password" — that
// one account, password only (see PasswordStepView). "Use a different
// account" — or having zero remembered accounts at all — lands on "form",
// the original blank Student ID + password form, kept inline below since
// it's already small.
type LoginView = 'picker' | 'password' | 'form';


// Shown on the Sign in button itself instead of (or as well as) a toast —
// a toast disappears on its own after a few seconds, which is misleading
// when the actual reason the student can't sign in (the rate limit) is
// still in effect. Keeping the reason printed on the button, and the
// button disabled, stays accurate for as long as the limit actually lasts
// — including across a refresh, since useIsRateLimited checks a persisted
// window rather than in-memory state.
const RATE_LIMITED_MESSAGE = 'Too many attempts. Try again later';

export function LoginPage() {
    const navigate = useNavigate();
    const login = useLogin();
    const acceptTerms = useAcceptTerms();
    const student = useAuthStore((state) => state.student);
    const token = useAuthStore((state) => state.token);
    const clearSession = useAuthStore((state) => state.clear);
    const rememberedAccounts = useRememberedAccountsStore((state) => state.accounts);
    const forgetAccount = useRememberedAccountsStore((state) => state.forget);

    const [view, setView] = useState<LoginView>(rememberedAccounts.length > 0 ? 'picker' : 'form');
    const [selectedAccount, setSelectedAccount] = useState<RememberedAccount | null>(null);
    const [studentNumber, setStudentNumber] = useState('');
    const [password, setPassword] = useState('');
    const [showTermsGate, setShowTermsGate] = useState(false);
    const [isLoggingOut, setIsLoggingOut] = useState(false);

    // Hooks can't be called conditionally, so both possible "who's trying
    // to sign in right now" checks run on every render; only the one
    // matching the current view is actually used below. Each one also
    // independently re-checks the persisted window on mount, which is what
    // makes the button correct immediately after a refresh.
    const isPasswordViewRateLimited = useIsRateLimited(selectedAccount?.studentNumber ?? '');
    const isFormViewRateLimited = useIsRateLimited(studentNumber);

    // Covers a page refresh mid-flow: a session that logged in but never
    // accepted/declined the modal lands back on /login (see GuestRoute) —
    // this re-opens the gate instead of silently letting the request
    // through or leaving the student stuck with no way to proceed.
    useEffect(() => {
        if (token && student && !student.hasAcceptedTerms) {
            setShowTermsGate(true);
        }
    }, [token, student]);

    // If the last remembered account gets removed while the picker is open,
    // fall through to the blank form rather than showing an empty list.
    useEffect(() => {
        if (view === 'picker' && rememberedAccounts.length === 0) {
            setView('form');
        }
    }, [view, rememberedAccounts.length]);

    function goPastLogin(mustChangePassword: boolean) {
        navigate(mustChangePassword ? '/change-password' : '/dashboard');
    }

    function submitLogin(username: string, submittedPassword: string) {
        login.mutate(
            { username, password: submittedPassword },
            {
                onSuccess: (result) => {
                    if (!result.student.hasAcceptedTerms) {
                        setShowTermsGate(true);
                        return;
                    }
                    goPastLogin(result.student.mustChangePassword);
                },
                onError: (error) => {
                    // A 429 here is Laravel's per-IP+username login throttle,
                    // not a wrong password — telling the student "incorrect
                    // student number or password" in that case is actively
                    // wrong and just gets them to keep retrying, which only
                    // extends the lockout. Record it and let the button
                    // reflect it instead of toasting something misleading.
                    if (isAxiosError(error) && error.response?.status === 429) {
                        recordLoginRateLimit(username, error);
                        return;
                    }

                    toast.error('Incorrect student number or password.');
                },
            },
        );
    }

    function handleFormSubmit(event: FormEvent) {
        event.preventDefault();
        submitLogin(studentNumber, password);
    }

    function handlePasswordStepSubmit(event: FormEvent) {
        event.preventDefault();
        if (!selectedAccount) return;
        submitLogin(selectedAccount.studentNumber, password);
    }

    function selectAccount(account: RememberedAccount) {
        setSelectedAccount(account);
        setPassword('');
        setView('password');
    }

    function backToPicker() {
        setSelectedAccount(null);
        setPassword('');
        setView('picker');
    }

    function useADifferentAccount() {
        setSelectedAccount(null);
        setStudentNumber('');
        setPassword('');
        setView('form');
    }

    function backToPickerFromForm() {
        setStudentNumber('');
        setPassword('');
        setView('picker');
    }

    function handleAcceptTerms() {
        acceptTerms.mutate(undefined, {
            onSuccess: () => {
                setShowTermsGate(false);
                goPastLogin(Boolean(student?.mustChangePassword));
            },
            onError: () => {
                toast.error('Could not save your acceptance. Please try again.');
            },
        });
    }

    async function handleLogoutFromGate() {
        setIsLoggingOut(true);
        try {
            await httpAuthRepository.logout();
        } finally {
            clearSession();
            setShowTermsGate(false);
            setIsLoggingOut(false);
        }
    }

    const termsGate = (
        <TermsGateDialog
            open={showTermsGate}
            isAccepting={acceptTerms.isPending}
            isLoggingOut={isLoggingOut}
            onAccept={handleAcceptTerms}
            onLogout={handleLogoutFromGate}
        />
    );

    if (view === 'picker') {
        return (
            <AuthLayout title="Welcome back" description="Choose your account to continue.">
                <AccountPickerView
                    accounts={rememberedAccounts}
                    onSelect={selectAccount}
                    onForget={forgetAccount}
                    onUseDifferentAccount={useADifferentAccount}
                />
                {termsGate}
            </AuthLayout>
        );
    }

    if (view === 'password' && selectedAccount) {
        return (
            <AuthLayout title="Sign in" description="Enter your password to continue.">
                <PasswordStepView
                    account={selectedAccount}
                    password={password}
                    onPasswordChange={setPassword}
                    onSubmit={handlePasswordStepSubmit}
                    onBack={backToPicker}
                    isSubmitting={login.isPending}
                    isRateLimited={isPasswordViewRateLimited}
                    rateLimitedLabel={RATE_LIMITED_MESSAGE}
                />
                {termsGate}
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title="Sign in" description="Use your Student ID Number and password.">
            <form onSubmit={handleFormSubmit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="studentNumber">Student ID Number</Label>
                    <Input
                        id="studentNumber"
                        autoComplete="username"
                        inputMode="numeric"
                        placeholder="e.g. 2023-1-05413"
                        value={studentNumber}
                        onChange={(event) => setStudentNumber(formatStudentNumber(event.target.value))}
                        onKeyDown={(event) => {
                            // Allow navigation/editing keys and any
                            // Ctrl/Cmd shortcut (e.g. Ctrl+A) through
                            // untouched; block every other non-digit key
                            // so letters/symbols never even flash in the
                            // field before the formatter strips them.
                            const allowedKeys = [
                                'Backspace', 'Delete', 'Tab', 'Enter', 'Escape',
                                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                                'Home', 'End',
                            ];
                            if (event.ctrlKey || event.metaKey || allowedKeys.includes(event.key)) {
                                return;
                            }
                            if (!/^[0-9]$/.test(event.key)) {
                                event.preventDefault();
                            }
                        }}
                        onPaste={(event) => event.preventDefault()}
                        required
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                        required
                    />
                </div>
                {/* Same move as PasswordStepView: the lockout is a notice in
                    its own right, not a label crammed onto the button. */}
                {isFormViewRateLimited && (
                    <div className={cn('flex items-start gap-4 rounded-2xl border p-4', TONE.red.wash)} role="alert">
                        <Tile tone="red" size="sm" variant="solid" Icon={ShieldAlert} />
                        <div className="min-w-0">
                            <Text variant="small" className="font-medium text-foreground">
                                {RATE_LIMITED_MESSAGE}
                            </Text>
                            <Text variant="caption">Too many sign-in attempts from this device.</Text>
                        </div>
                    </div>
                )}

                <Button type="submit" className="w-full" disabled={login.isPending || isFormViewRateLimited}>
                    {login.isPending ? 'Signing in…' : 'Sign in'}
                </Button>

                <div className="rounded-2xl bg-muted p-4">
                    <Text variant="caption" className="text-center">
                        First time signing in? Your default password was given to you by your department.
                    </Text>
                </div>
                {rememberedAccounts.length > 0 && (
                    <button
                        type="button"
                        onClick={backToPickerFromForm}
                        className="block w-full text-center text-small text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        Back to your saved accounts
                    </button>
                )}
            </form>
            {termsGate}
        </AuthLayout>
    );
}
