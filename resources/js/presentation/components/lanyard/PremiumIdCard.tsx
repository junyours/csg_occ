import csgLogo from '@/assets/csg-logo.png';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';

export interface PremiumIdCardProps {
    firstName: string;
    lastName: string;
    studentNumber: string;
    section: string | null;
    qrUrl: string;
    photoUrl?: string | null;
    course?: string | null;
    className?: string;
}

function initials(firstName: string, lastName: string) {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
}

/**
 * A plain-DOM, light-theme student ID card. Every dimension is fluid
 * (clamp()/vw-based) so the card — and the QR inside it — scale to whatever
 * viewport it's dropped into. It stays light even in dark mode on purpose:
 * it's standing in for a physical laminated card, and a dark one would
 * scan worse under a gate light as well as looking wrong.
 *
 * What changed in the redesign: the drop shadow is violet-tinted rather
 * than neutral black. That's the same trick as the tiles — a shadow in the
 * object's own hue instead of a grey one — just at card scale, and it's
 * what stops a white card on a white page from looking like a flat panel.
 * The course/section chips picked up the app's tinted-chip treatment too,
 * so they match the badges on the dashboard.
 */
export function PremiumIdCard({
    firstName,
    lastName,
    studentNumber,
    section,
    qrUrl,
    photoUrl,
    course,
    className,
}: PremiumIdCardProps) {
    return (
        <div
            className={cn(
                'relative flex w-[min(92vw,24rem)] flex-col items-center gap-[clamp(0.75rem,3vw,1.25rem)] rounded-[clamp(1.5rem,5vw,2rem)] border border-neutral-200 bg-white px-[clamp(1.25rem,5vw,1.75rem)] py-[clamp(1.5rem,5vw,2rem)]',
                'shadow-[0_24px_70px_-20px_rgba(109,40,217,0.35),0_8px_24px_-12px_rgba(0,0,0,0.18)]',
                className,
            )}
        >
            {/* Avatar, name, course/section — the "ID" part of the card */}
            <div className="flex w-full flex-col items-center gap-[clamp(0.375rem,1.5vw,0.625rem)] text-center">
                <Avatar className="size-[clamp(3.5rem,16vw,4.5rem)] shadow-lg shadow-violet-500/20 ring-4 ring-white">
                    {photoUrl ? <AvatarImage src={photoUrl} alt={`${firstName} ${lastName}`} /> : null}
                    <AvatarFallback className="bg-violet-500/10 text-violet-600">{initials(firstName, lastName)}</AvatarFallback>
                </Avatar>

                <div className="flex w-full flex-col items-center gap-2">
                    <div className="text-h3 font-semibold text-neutral-900">
                        {firstName} {lastName}
                    </div>
                    <div className="font-mono text-small tracking-[0.08em] text-neutral-500">{studentNumber}</div>
                </div>

                {(course || section) && (
                    <div className="flex flex-wrap items-center justify-center gap-2">
                        {course && (
                            <span className="rounded-full bg-violet-500/10 px-4 py-2 text-caption font-medium tracking-wide text-violet-700 uppercase">
                                {course}
                            </span>
                        )}
                        {section && (
                            <span className="rounded-full bg-sky-500/10 px-4 py-2 text-caption font-medium tracking-wide text-sky-700 uppercase">
                                {section}
                            </span>
                        )}
                    </div>
                )}
            </div>

            {/* QR, logo badge centered on top of it — sized as a fraction of
                the card's own (already fluid) width via aspect-square, not
                a fixed pixel box, so it never overflows a narrow screen or
                looks tiny on a wide one. The frame stays pure white and
                un-tinted: anything washed over a QR costs the scanner
                contrast, which is the one thing this card can't trade. */}
            <div className="relative flex aspect-square w-full shrink-0 items-center justify-center rounded-3xl border border-neutral-200 bg-white p-[clamp(0.5rem,3vw,0.75rem)] shadow-inner">
                <img
                    src={qrUrl}
                    alt="Your attendance QR code"
                    className="size-full object-contain"
                    style={{ imageRendering: 'pixelated' }}
                />
                <div className="shimmer-badge absolute flex size-[clamp(1.75rem,8vw,2.125rem)] items-center justify-center rounded-full bg-white p-0 shadow-sm ring-1 ring-neutral-200">
                    <img src={csgLogo} alt="" className="size-full scale-125 object-contain" />
                </div>
            </div>

            <div className="text-center text-caption text-neutral-400">Present this QR to the attendance scanner.</div>
        </div>
    );
}
