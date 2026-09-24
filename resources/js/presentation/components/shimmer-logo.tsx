import type { CSSProperties } from 'react';

import { cn } from '@/lib/utils';

interface ShimmerLogoProps {
    src: string;
    alt: string;
    /** Stagger the shine sweep so a row of logos doesn't shine in unison. */
    delaySeconds?: number;
    className?: string;
}

export function ShimmerLogo({ src, alt, delaySeconds = 0, className }: ShimmerLogoProps) {
    return (
        <div
            className={cn(
                'shimmer-logo flex size-12 items-center justify-center rounded-xl border border-border bg-white p-2 shadow-sm',
                className,
            )}
            style={{ '--shimmer-delay': `${delaySeconds}s` } as CSSProperties}
        >
            <img src={src} alt={alt} className="h-full w-full object-contain" draggable={false} />
        </div>
    );
}
