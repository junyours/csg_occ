import { type FormEvent, useState } from 'react';
import { isAxiosError } from 'axios';
import { Check, Info, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { useChangePassword } from '@/application/auth/use-change-password';
import { evaluatePasswordPolicy } from '@/domain/password-policy';
import { PasswordRequirementsList } from '@/presentation/components/auth/password-requirements-list';
import { cn } from '@/lib/utils';

interface ChangePasswordDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * Change password from inside the app (Personal information). Needs the
 * current password, then a new one that passes the same checklist as the
 * first-login and forgot-password screens.
 *
 * Someone who is still signed in but has forgotten their current password
 * can't get past the first field on purpose — the note under it tells them
 * to sign out and use "Forgot password?" instead, which goes through an
 * officer-issued code.
 */
export function ChangePasswordDialog({ open, onOpenChange }: ChangePasswordDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {/* The form is its own component, mounted only while the dialog is
                open, so every open starts with empty fields. */}
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <ChangePasswordForm onDone={() => onOpenChange(false)} />
            </DialogContent>
        </Dialog>
    );
}

function ChangePasswordForm({ onDone }: { onDone: () => void }) {
    const changePassword = useChangePassword();
    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');

    const policy = evaluatePasswordPolicy(newPassword);
    const hasTypedConfirmation = confirmation.length > 0;
    const passwordsMatch = hasTypedConfirmation && newPassword === confirmation;
    const sameAsCurrent = newPassword.length > 0 && newPassword === currentPassword;
    const canSubmit =
        currentPassword.length > 0 && policy.isValid && passwordsMatch && !sameAsCurrent && !changePassword.isPending;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        if (!canSubmit) return;

        changePassword.mutate(
            {
                current_password: currentPassword,
                new_password: newPassword,
                new_password_confirmation: confirmation,
            },
            {
                onSuccess: () => {
                    toast.success('Password changed.');
                    onDone();
                },
                onError: (error) => {
                    // Wrong current password vs. a validation failure — show
                    // the backend's actual reason rather than a generic one.
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
        <>
            <DialogHeader>
                <DialogTitle>Change password</DialogTitle>
                <DialogDescription>Enter your current password, then choose a new one.</DialogDescription>
            </DialogHeader>

            <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                <div className="space-y-2">
                    <Label htmlFor="changeCurrentPassword">Current password</Label>
                    <PasswordInput
                        id="changeCurrentPassword"
                        autoComplete="current-password"
                        autoFocus
                        value={currentPassword}
                        onChange={(event) => setCurrentPassword(event.target.value)}
                        required
                    />
                    <div className="flex items-start gap-2 rounded-xl bg-muted p-3 text-xs text-muted-foreground">
                        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                        <p>
                            If you don&apos;t know your current password, sign out and tap{' '}
                            <span className="font-medium text-foreground">Forgot password?</span> on the sign-in
                            screen to request a new one.
                        </p>
                    </div>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="changeNewPassword">New password</Label>
                    <PasswordInput
                        id="changeNewPassword"
                        autoComplete="new-password"
                        value={newPassword}
                        onChange={(event) => setNewPassword(event.target.value)}
                        required
                    />
                    <PasswordRequirementsList password={newPassword} />
                    {sameAsCurrent ? (
                        <p className="flex items-center gap-2 text-xs text-destructive">
                            <X className="size-3.5 shrink-0" aria-hidden />
                            New password must be different from your current one
                        </p>
                    ) : null}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="changeConfirmPassword">Confirm new password</Label>
                    <PasswordInput
                        id="changeConfirmPassword"
                        autoComplete="new-password"
                        value={confirmation}
                        onChange={(event) => setConfirmation(event.target.value)}
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

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onDone} disabled={changePassword.isPending}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={!canSubmit}>
                        {changePassword.isPending ? 'Saving…' : 'Save password'}
                    </Button>
                </DialogFooter>
            </form>
        </>
    );
}
