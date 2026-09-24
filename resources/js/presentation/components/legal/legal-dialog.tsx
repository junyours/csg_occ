import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { LegalDocumentBody } from '@/presentation/components/legal/legal-document-body';
import { PRIVACY_CONTENT, TERMS_CONTENT } from '@/presentation/components/legal/legal-content';

interface LegalDialogProps {
    open: 'terms' | 'privacy' | null;
    onOpenChange: (open: boolean) => void;
}

/**
 * Read-only viewer for the login screen's "Terms & Conditions" / "Privacy
 * Policy" footer links — anyone can open this, signed in or not, and it
 * closes like a normal dialog. Distinct from the blocking agreement page
 * shown on first login (see agreement-page.tsx), which must be accepted.
 */
export function LegalDialog({ open, onOpenChange }: LegalDialogProps) {
    const document = open === 'privacy' ? PRIVACY_CONTENT : TERMS_CONTENT;

    return (
        <Dialog open={open !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[80vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{document.title}</DialogTitle>
                </DialogHeader>
                <LegalDocumentBody document={document} />
            </DialogContent>
        </Dialog>
    );
}
