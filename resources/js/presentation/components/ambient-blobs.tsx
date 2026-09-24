import { cn } from '@/lib/utils';

/**
 * The "moving abstract" layer behind the CSG gradient. Three soft, blurred
 * shapes drifting on independent slow loops (see `.csg-blob-a` /
 * `.csg-blob-b` in app.css) — enough to keep the gradient from feeling like
 * a flat, static paint fill, without ever drawing the eye away from the
 * actual content in front of it.
 *
 * Purely decorative (`aria-hidden`, `pointer-events-none`), and every shape
 * is a tint of the brand's own violet/magenta family, so it reads as "the
 * gradient breathing" rather than a random accent color. Respects
 * prefers-reduced-motion via the shared `.csg-blob-*` classes.
 */
export function AmbientBlobs({ className }: { className?: string }) {
    return (
        <div aria-hidden className={cn('pointer-events-none absolute inset-0 -z-10 overflow-hidden', className)}>
            <span className="csg-blob csg-blob-a absolute -top-32 -left-24 size-80 bg-fuchsia-400/25 sm:size-96" />
            <span
                className="csg-blob csg-blob-b absolute top-1/3 -right-28 size-72 bg-purple-300/20 sm:size-[26rem]"
                style={{ animationDelay: '-7s' }}
            />
            <span
                className="csg-blob csg-blob-a absolute -bottom-24 left-1/4 size-72 bg-pink-400/15 sm:size-80"
                style={{ animationDelay: '-11s' }}
            />
        </div>
    );
}
