import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/application/auth/auth.store';
import { useAcceptTerms } from '@/application/auth/use-accept-terms';
import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
// import { Text } from '@/presentation/components/typography';
import { AuthLayout } from '@/presentation/layouts/auth-layout';
import { LegalDocumentBody } from '@/presentation/components/legal/legal-document-body';
import { PRIVACY_CONTENT, TERMS_CONTENT } from '@/presentation/components/legal/legal-content';
import { cn } from '@/lib/utils';

/**
 * Shown once, right after a student's very first successful login (gated
 * in ProtectedRoute on !student.hasAcceptedTerms). Accepting persists
 * has_accepted_terms server-side, so this screen never appears again for
 * that account.
 */
export function AgreementPage() {
    const navigate = useNavigate();
    const student = useAuthStore((state) => state.student);
    const clearSession = useAuthStore((state) => state.clear);
    const acceptTerms = useAcceptTerms();
    const [activeDoc, setActiveDoc] = useState<'terms' | 'privacy'>('terms');
    const [agreed, setAgreed] = useState(false);
    const [isSigningOut, setIsSigningOut] = useState(false);

    const document = activeDoc === 'terms' ? TERMS_CONTENT : PRIVACY_CONTENT;

    async function handleSignOut() {
        setIsSigningOut(true);
        try {
            await httpAuthRepository.logout();
        } finally {
            clearSession();
            navigate('/login', { replace: true });
        }
    }

    function handleAccept() {
        if (!agreed) return;
        acceptTerms.mutate(undefined, {
            onSuccess: () => {
                navigate(student?.mustChangePassword ? '/change-password' : '/dashboard');
            },
            onError: () => {
                toast.error('Could not save your acceptance. Please try again.');
            },
        });
    }

    return (
        <AuthLayout title="Before you continue" description="Please review and accept to keep using OCC CSG.">
            <div className="space-y-4">
                <div className="flex rounded-md border border-border bg-muted p-2 text-sm">
                    <button
                        type="button"
                        onClick={() => setActiveDoc('terms')}
                        className={cn(
                            'flex-1 rounded-sm px-4 py-2 font-medium transition-colors',
                            activeDoc === 'terms' ? 'bg-background shadow-sm' : 'text-muted-foreground',
                        )}
                    >
                        Terms
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveDoc('privacy')}
                        className={cn(
                            'flex-1 rounded-sm px-4 py-2 font-medium transition-colors',
                            activeDoc === 'privacy' ? 'bg-background shadow-sm' : 'text-muted-foreground',
                        )}
                    >
                        Privacy
                    </button>
                </div>

                <div className="max-h-64 overflow-y-auto rounded-md border border-border p-4">
                    <LegalDocumentBody document={document} />
                </div>

                <label className="flex items-start gap-2 text-small">
                    <input
                        type="checkbox"
                        checked={agreed}
                        onChange={(event) => setAgreed(event.target.checked)}
                        className="mt-0.5 size-4 shrink-0 rounded border-border accent-primary"
                    />
                    <span>
                        I have read and agree to the <strong>Terms &amp; Conditions</strong> and{' '}
                        <strong>Privacy Policy</strong>.
                    </span>
                </label>

                <Button
                    type="button"
                    className="w-full"
                    disabled={!agreed || acceptTerms.isPending}
                    onClick={handleAccept}
                >
                    {acceptTerms.isPending ? 'Saving…' : 'Accept & continue'}
                </Button>

                <button
                    type="button"
                    onClick={handleSignOut}
                    disabled={isSigningOut}
                    className="w-full text-center text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none disabled:opacity-50"
                >
                    {isSigningOut ? 'Signing out…' : 'Not you? Sign out'}
                </button>
            </div>
        </AuthLayout>
    );
}
