import { type FormEvent, useState } from 'react';
import { Check, X } from 'lucide-react';

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
import { evaluatePasswordPolicy } from '@/domain/password-policy';
import { PasswordRequirementsList } from '@/presentation/components/auth/password-requirements-list';
import { cn } from '@/lib/utils';

interface ResetPasswordDialogProps {
    open: boolean;
    isSubmitting: boolean;
    onSubmit: (newPassword: string, confirmation: string) => void;
    /** Abandons the reset and returns to sign-in. The verified code is spent, so there is no going back to it. */
    onCancel: () => void;
}

/**
 * The "choose a new password" step of forgot-password. Blocking, like the
 * terms dialog: no close button, no outside-click or Escape dismiss — the
 * only ways out are saving the password or cancelling back to sign-in.
 * Enforces the same checklist as the first-login change-password screen.
 */
export function ResetPasswordDialog({ open, isSubmitting, onSubmit, onCancel }: ResetPasswordDialogProps) {
    const [newPassword, setNewPassword] = useState('');
    const [confirmation, setConfirmation] = useState('');

    const policy = evaluatePasswordPolicy(newPassword);
    const hasTypedConfirmation = confirmation.length > 0;
    const passwordsMatch = hasTypedConfirmation && newPassword === confirmation;
    const canSubmit = policy.isValid && passwordsMatch && !isSubmitting;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        if (!canSubmit) return;
        onSubmit(newPassword, confirmation);
    }

    return (
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                showCloseButton={false}
                className="max-h-[90vh] overflow-y-auto"
                onInteractOutside={(event) => event.preventDefault()}
                onEscapeKeyDown={(event) => event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Set a new password</DialogTitle>
                    <DialogDescription>
                        Your code was accepted. Choose a new password to finish — you&apos;ll sign in with it next.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit} className="space-y-4" noValidate>
                    <div className="space-y-2">
                        <Label htmlFor="resetNewPassword">New password</Label>
                        <PasswordInput
                            id="resetNewPassword"
                            autoComplete="new-password"
                            autoFocus
                            value={newPassword}
                            onChange={(event) => setNewPassword(event.target.value)}
                            required
                        />
                        <PasswordRequirementsList password={newPassword} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="resetConfirmPassword">Confirm new password</Label>
                        <PasswordInput
                            id="resetConfirmPassword"
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
                        <Button type="button" variant="outline" onClick={onCancel} disabled={isSubmitting}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={!canSubmit}>
                            {isSubmitting ? 'Saving…' : 'Save password'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
