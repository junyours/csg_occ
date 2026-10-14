import { Radio } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * The red "live" tag: square corners, white bold text, pulsing broadcast
 * icon. One component so the dashboard, the events list and the event
 * detail header all show "this is running right now" the same way.
 */
export function LiveTag({ label = 'Active', className }: { label?: string; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center gap-1 rounded-sm bg-red-600 px-2 py-1 text-caption leading-none font-bold tracking-wide text-white uppercase',
                className,
            )}
        >
            <Radio className="size-3 animate-pulse" />
            {label}
        </span>
    );
}
