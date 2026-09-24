import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { LegalDocumentBody } from '@/presentation/components/legal/legal-document-body';
import { TERMS_CONTENT } from '@/presentation/components/legal/legal-content';

interface TermsGateDialogProps {
    open: boolean;
    isAccepting: boolean;
    isLoggingOut: boolean;
    onAccept: () => void;
    onLogout: () => void;
}

/**
 * Shown right on the login screen the moment a first-time login succeeds —
 * not a separate route/page. Blocking: no close button, no dismiss via
 * outside click/Escape. The only ways out are "I Accept" (persists
 * has_accepted_terms, then continues the normal post-login redirect) or
 * "Log out" (ends the session and stays on /login).
 */
export function TermsGateDialog({ open, isAccepting, isLoggingOut, onAccept, onLogout }: TermsGateDialogProps) {
    return (
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                showCloseButton={false}
                className="max-h-[85vh] overflow-y-auto"
                onInteractOutside={(event) => event.preventDefault()}
                onEscapeKeyDown={(event) => event.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>{TERMS_CONTENT.title}</DialogTitle>
                </DialogHeader>

                <div className="max-h-72 overflow-y-auto rounded-md border border-border p-4">
                    <LegalDocumentBody document={TERMS_CONTENT} />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onLogout}
                        disabled={isAccepting || isLoggingOut}
                    >
                        {isLoggingOut ? 'Logging out…' : 'Log out'}
                    </Button>
                    <Button type="button" onClick={onAccept} disabled={isAccepting || isLoggingOut}>
                        {isAccepting ? 'Saving…' : 'I Accept'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
