import { type ReactNode, useState } from 'react';
import { X } from 'lucide-react';

import { useAuthStore } from '@/application/auth/auth.store';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Role } from '@/domain/enums';
import { cn } from '@/lib/utils';

interface AvatarLightboxProps {
    /** The student's photo. No photo = nothing to enlarge, so nothing becomes clickable. */
    photoUrl: string | null | undefined;
    /** Used for the accessible labels and the caption under the photo. */
    name: string;
    /** Shape of the clickable area — match the avatar it wraps (circle by default). */
    className?: string;
    /** The avatar itself, rendered exactly as before. */
    children: ReactNode;
}

/**
 * Wraps a student's avatar so it opens full screen for a closer look —
 * for the System Admin only. For everyone else (and for a student with no
 * photo) it renders the avatar untouched: not a button, no pointer cursor,
 * nothing different from before.
 *
 * Tapping anywhere on the dark backdrop, the photo, the close button or
 * pressing Esc closes it. stopPropagation on the trigger means an avatar
 * inside a clickable row opens the photo without also triggering the row.
 */
export function AvatarLightbox({ photoUrl, name, className, children }: AvatarLightboxProps) {
    const isSystemAdmin = useAuthStore((state) => state.student?.role === Role.SystemAdmin);
    const [open, setOpen] = useState(false);

    if (!isSystemAdmin || !photoUrl) return <>{children}</>;

    return (
        <>
            <button
                type="button"
                aria-label={`View ${name}'s photo full screen`}
                onClick={(event) => {
                    event.stopPropagation();
                    setOpen(true);
                }}
                className={cn(
                    'inline-flex shrink-0 cursor-zoom-in rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                    className,
                )}
            >
                {children}
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                {/* The base DialogContent is a centred card; these classes
                    stretch it to cover the whole viewport instead (h-dvh so
                    mobile browser bars don't cut the photo). */}
                <DialogContent
                    showCloseButton={false}
                    onClick={() => setOpen(false)}
                    className="top-0 left-0 flex h-dvh w-screen max-w-none translate-x-0 translate-y-0 flex-col items-center justify-center gap-4 rounded-none border-0 bg-black/95 p-4 sm:max-w-none"
                >
                    <DialogTitle className="sr-only">{name}</DialogTitle>
                    <DialogDescription className="sr-only">Full-screen photo of {name}. Tap anywhere to close.</DialogDescription>

                    <img
                        src={photoUrl}
                        alt={name}
                        className="max-h-[80dvh] max-w-full rounded-lg object-contain shadow-2xl"
                    />
                    <p className="max-w-full truncate text-center text-small font-medium text-white">{name}</p>

                    <DialogClose
                        aria-label="Close"
                        className="absolute right-4 top-[max(1rem,env(safe-area-inset-top))] flex size-10 items-center justify-center rounded-full bg-white/15 text-white transition-colors hover:bg-white/25 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    >
                        <X className="size-5" />
                    </DialogClose>
                </DialogContent>
            </Dialog>
        </>
    );
}
