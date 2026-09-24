import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import { TONE, type Tone } from '@/presentation/components/tone';

/**
 * "There's nothing here" and "that didn't work", in one shape.
 *
 * Before, these were scattered one-liners — a bare `<Text variant="small">No
 * events yet.</Text>` floating where a list should be. That reads as a
 * rendering failure rather than a state. A tile, a sentence, and a way
 * forward is the whole job, and it's the same job on every screen.
 *
 * `tone` does double duty: neutral for "nothing yet" (calm), red for "this
 * broke", amber for "you need to do something first".
 */
export function EmptyState({
    Icon,
    title,
    description,
    tone = 'neutral',
    action,
    className,
}: {
    Icon: LucideIcon;
    title: string;
    description?: ReactNode;
    tone?: Tone;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <Card className={cn(tone !== 'neutral' && cn('border', TONE[tone].wash), className)}>
            <CardContent className="flex flex-col items-center gap-4 py-4 text-center">
                <Tile tone={tone} size="lg" variant={tone === 'neutral' ? 'soft' : 'solid'} Icon={Icon} />
                <div className="space-y-2">
                    <Text className="font-medium">{title}</Text>
                    {description && (
                        <Text variant="small" className="mx-auto max-w-sm">
                            {description}
                        </Text>
                    )}
                </div>
                {action}
            </CardContent>
        </Card>
    );
}

/**
 * A tinted squircle, a label, and whatever belongs on the right. Same
 * component the dashboard uses above each section — pulled out here so
 * every screen's section headers line up at the same size and spacing.
 */
export function SectionHeader({
    Icon,
    tone,
    children,
    action,
}: {
    Icon: LucideIcon;
    tone: Tone;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <div className="flex items-center gap-2 px-0.5">
            <Tile tone={tone} size="sm" variant="soft" Icon={Icon} />
            <Text variant="small" className="min-w-0 flex-1 truncate font-medium text-foreground">
                {children}
            </Text>
            {action}
        </div>
    );
}

/** Rows of skeleton cards, for lists that are still loading. */
export function ListSkeleton({ rows = 3, className }: { rows?: number; className?: string }) {
    return (
        <div className={cn('space-y-2', className)} aria-hidden>
            {Array.from({ length: rows }, (_, index) => (
                <div key={index} className="h-20 animate-pulse rounded-2xl bg-muted" />
            ))}
        </div>
    );
}
