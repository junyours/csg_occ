import { useEffect, useState } from 'react';

import { cn } from '@/lib/utils';
import { TONE, type Tone } from '@/presentation/components/tone';

const SIZE = {
    sm: 'size-10',
    md: 'size-12',
} as const;

/**
 * A department's logo, with the department's own abbreviation (BSIT, BSED,
 * BEED, BSBA…) as the fallback instead of a broken-image icon or a generic
 * building glyph. The fallback kicks in both when no logo was ever uploaded
 * and when the stored file 404s / fails to load (onError).
 */
export function DepartmentLogo({
    code,
    logoUrl,
    tone,
    size = 'md',
    variant = 'soft',
    className,
}: {
    code: string;
    logoUrl: string | null | undefined;
    tone: Tone;
    size?: keyof typeof SIZE;
    variant?: 'solid' | 'soft';
    className?: string;
}) {
    const [failed, setFailed] = useState(false);

    // A new upload gets a fresh chance even if the previous URL had failed.
    useEffect(() => setFailed(false), [logoUrl]);

    if (logoUrl && !failed) {
        return (
            <span
                className={cn(
                    'flex shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-white p-1',
                    SIZE[size],
                    TONE[tone].wash,
                    className,
                )}
            >
                <img
                    src={logoUrl}
                    alt={code}
                    onError={() => setFailed(true)}
                    className="size-full object-contain"
                />
            </span>
        );
    }

    return (
        <span
            role="img"
            aria-label={code}
            className={cn(
                'flex shrink-0 items-center justify-center overflow-hidden rounded-lg font-bold tracking-tight',
                // Codes are short (4 letters), but a longer one shrinks rather than overflows.
                code.length > 4 ? 'text-[10px]' : 'text-xs',
                SIZE[size],
                TONE[tone][variant],
                className,
            )}
        >
            {code}
        </span>
    );
}
