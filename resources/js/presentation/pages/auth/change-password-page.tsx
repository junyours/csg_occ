import { type FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { Check, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { useAuthStore } from '@/application/auth/auth.store';
import { evaluatePasswordPolicy } from '@/domain/password-policy';
import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { AuthLayout } from '@/presentation/layouts/auth-layout';
import { PasswordRequirementsList } from '@/presentation/components/auth/password-requirements-list';
import { cn } from '@/lib/utils';
import { useChangePassword } from '@/application/auth/use-change-password';

export function ChangePasswordPage() {
    const navigate = useNavigate();
    const changePassword = useChangePassword();
    const clearSession = useAuthStore((state) => state.clear);
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [newPasswordConfirmation, setNewPasswordConfirmation] = useState('');
    const [isSigningOut, setIsSigningOut] = useState(false);

    const policy = evaluatePasswordPolicy(newPassword);
    const hasTypedConfirmation = newPasswordConfirmation.length > 0;
    const passwordsMatch = hasTypedConfirmation && newPassword === newPasswordConfirmation;
    const canSubmit =
        currentPassword.length > 0 && policy.isValid && passwordsMatch && !changePassword.isPending;

    // Doesn't skip the password change itself (must_change_password stays
    // true — the guard still forces this screen on the next login). It
    // only lets someone who signed into the wrong account back out to
    // /login without being stuck here, by ending this session properly.
    async function handleSignOut() {
        setIsSigningOut(true);
        try {
            await httpAuthRepository.logout();
        } finally {
            clearSession();
            navigate('/login', { replace: true });
        }
    }

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        if (!canSubmit) return;

        changePassword.mutate(
            {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: newPasswordConfirmation,
            },
            {
                onSuccess: () => {
                    toast.success('Password changed.');
                    navigate('/dashboard');
                },
                onError: (error) => {
                    // Surface the backend's actual reason (wrong current
                    // password vs. a validation failure) instead of a
                    // generic message for every case.
                    const message =
                        isAxiosError(error) && typeof error.response?.data?.message === 'string'
                            ? error.response.data.message
                            : 'Could not change your password. Please try again.';
                    toast.error(message);
                },
            },
        );
    }

    return (
        <AuthLayout title="Set a new password" description="You must change your password before continuing.">
            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <div className="space-y-2">
                    <Label htmlFor="currentPassword">Current password</Label>
                    <PasswordInput
                        id="currentPassword"
                        autoComplete="current-password"
                        value={currentPassword}
                        onChange={(event) => setCurrentPassword(event.target.value)}
                        required
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="newPassword">New password</Label>
                    <PasswordInput
                        id="newPassword"
                        autoComplete="new-password"
                        value={newPassword}
                        onChange={(event) => setNewPassword(event.target.value)}
                        required
                    />
                    {/* Live requirements checklist + strength bar — updates on every keystroke */}
                    <PasswordRequirementsList password={newPassword} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="newPasswordConfirmation">Confirm new password</Label>
                    <PasswordInput
                        id="newPasswordConfirmation"
                        autoComplete="new-password"
                        value={newPasswordConfirmation}
                        onChange={(event) => setNewPasswordConfirmation(event.target.value)}
                        aria-invalid={hasTypedConfirmation && !passwordsMatch}
                        required
                    />
                    {hasTypedConfirmation ? (
                        <p
                            className={cn(
                                'flex items-center gap-2 text-xs',
                                passwordsMatch ? 'text-emerald-600' : 'text-destructive',
                            )}
                        >
                            {passwordsMatch ? (
                                <Check className="size-3.5 shrink-0" aria-hidden />
                            ) : (
                                <X className="size-3.5 shrink-0" aria-hidden />
                            )}
                            {passwordsMatch ? 'Passwords match' : 'Passwords do not match'}
                        </p>
                    ) : null}
                </div>

                <Button type="submit" className="w-full" disabled={!canSubmit}>
                    {changePassword.isPending ? 'Saving…' : 'Save password'}
                </Button>

                <button
                    type="button"
                    onClick={handleSignOut}
                    disabled={isSigningOut}
                    className="w-full text-center text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none disabled:opacity-50"
                >
                    {isSigningOut ? 'Signing out…' : 'Not you? Sign out'}
                </button>
            </form>
        </AuthLayout>
    );
}
