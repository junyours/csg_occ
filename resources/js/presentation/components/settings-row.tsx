import type { PropsWithChildren, ReactNode } from 'react';
import { ChevronRight } from 'lucide-react';
import { Link } from 'react-router-dom';

import { cn } from '@/lib/utils';
import { Tile } from '@/presentation/components/tile';
import type { Tone } from '@/presentation/components/tone';

interface SettingsRowProps extends PropsWithChildren {
    to?: string;
    /** A lucide icon element, e.g. `<UserRound />` — sizing is handled by Tile. */
    icon?: ReactNode;
    /** Hue for the icon tile. Defaults to violet; `destructive` forces red. */
    tone?: Tone;
    onClick?: () => void;
    destructive?: boolean;
    /** Optional right-aligned hint shown before the chevron, e.g. a balance or a count. */
    trailing?: ReactNode;
}

/**
 * A row in a settings list. The icon now sits in a tinted squircle instead
 * of floating as a grey glyph — which is what lets each row be told apart
 * at a glance in a stack of five, and what ties this list to every other
 * surface in the app.
 */
export function SettingsRow({ to, icon, tone, onClick, destructive, trailing, children }: SettingsRowProps) {
    const resolvedTone: Tone = destructive ? 'red' : (tone ?? 'violet');

    const content = (
        <>
            {icon ? (
                <Tile tone={resolvedTone} size="md" variant="soft">
                    {icon}
                </Tile>
            ) : null}
            <span className={cn('min-w-0 flex-1 truncate text-body', destructive && 'font-medium text-destructive')}>{children}</span>
            {trailing ? <span className="shrink-0 text-small font-medium tabular-nums text-muted-foreground">{trailing}</span> : null}
            {to ? <ChevronRight className="size-4 shrink-0 text-muted-foreground" /> : null}
        </>
    );

    const className = cn(
        'flex w-full items-center gap-4 rounded-2xl border border-border bg-card p-4 text-left transition-all',
        destructive ? 'hover:border-red-500/30 hover:bg-red-500/5' : 'hover:shadow-md active:scale-[0.995]',
    );

    if (to) {
        return (
            <Link to={to} className={className}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" onClick={onClick} className={className}>
            {content}
        </button>
    );
}
