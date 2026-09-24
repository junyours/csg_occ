import type { ReactNode } from 'react';
import { motion } from 'framer-motion';
import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Text } from '@/presentation/components/typography';
import { AnimatedCounter } from '@/presentation/components/dashboard/animated-counter';
import { TONE, type Tone } from '@/presentation/components/tone';

/**
 * The squircle. One shape, three sizes, two weights — this is the atom the
 * whole dashboard is built from now, so the streak tile, a stat icon, a
 * department badge and a leaderboard rank are all visibly the same object
 * at different scales.
 *
 * Sizes are deliberately few. The radius grows with the box (xl → 2xl) so
 * the corner curve stays visually constant instead of looking tighter on
 * the big tiles.
 *
 * Every size is a multiple of 8 — 32 / 48 / 64 (see spacing.ts). `md` used
 * to be 44px, which put a half-pixel of drift into every row it sat in:
 * a 44px tile can't share an edge with anything else on the scale, so the
 * text beside it never quite lined up with the text in the row above. At
 * 48 it's also exactly the tap-target height, so a Tile in a list row now
 * *is* the row's height rather than something the row has to pad around.
 */
const TILE_SIZE = {
    sm: 'size-8 rounded-xl',
    md: 'size-12 rounded-2xl',
    lg: 'size-16 rounded-2xl',
} as const;

/**
 * Each icon is half its box — 16 in 32, 24 in 48, 32 in 64. Glyph sizes
 * follow the type scale rather than the spacing scale, but holding one
 * ratio across all three sizes is what keeps a small tile and a large one
 * looking like the same object rather than two different ones.
 */
const TILE_ICON = {
    sm: 'size-4',
    md: 'size-6',
    lg: 'size-8',
} as const;

export function Tile({
    tone,
    size = 'md',
    variant = 'solid',
    Icon,
    children,
    className,
}: {
    tone: Tone;
    size?: keyof typeof TILE_SIZE;
    variant?: 'solid' | 'soft';
    Icon?: LucideIcon;
    children?: ReactNode;
    className?: string;
}) {
    return (
        <span
            className={cn(
                // gap-1 (4px) is the documented half-step: this is the icon
                // stacked over its own numeral inside a 48px box, where the
                // 8px base unit would be wider than the box can carry.
                'flex shrink-0 flex-col items-center justify-center gap-1',
                TILE_SIZE[size],
                TONE[tone][variant],
                className,
            )}
        >
            {Icon && <Icon className={TILE_ICON[size]} />}
            {children}
        </span>
    );
}

/**
 * Tile + label + number, in the streak card's own arrangement: the lit
 * square on the left carrying the icon, the reading stacked to the right of
 * it — big figure over a quiet caption.
 *
 * Replaces what used to be three near-identical components (StatPill,
 * StatChip, MiniStat), each with its own padding, radius and tone map. They
 * were drifting apart; one component means a Present count looks the same
 * whether it's on the student dashboard or the admin one.
 */
export function StatTile({
    label,
    value,
    Icon,
    tone,
    variant = 'soft',
    format,
    size = 'md',
    className,
}: {
    label: ReactNode;
    value: number;
    Icon: LucideIcon;
    tone: Tone;
    /** `solid` promotes this stat to the card's focal point. Use once per card. */
    variant?: 'solid' | 'soft';
    format?: (n: number) => string;
    /** `sm` shrinks the icon tile and padding for tight, multi-up rows (e.g. 3 stats sharing a mobile card). */
    size?: 'sm' | 'md';
    className?: string;
}) {
    return (
        <div
            className={cn(
                // 16px padding, 16px tile-to-text. With a 48px `md` tile that
                // puts the whole stat at 80px tall — 10 units, so two of them
                // side by side line up with anything else on the page.
                'flex min-w-0 items-center gap-4 rounded-2xl border bg-card p-4',
                // The tight variant drops to the 8px step throughout, landing
                // at 48px with its 32px `sm` tile.
                size === 'sm' && 'gap-2 p-2',
                variant === 'solid' && TONE[tone].wash,
                className,
            )}
        >
            <Tile tone={tone} size={size === 'sm' ? 'sm' : 'md'} variant={variant} Icon={Icon} />
            {/* min-w-0 lets this column shrink inside a tight flex/grid row, but only
                the label truncates — the number must never lose digits, so it gets
                its own line with no truncation and no wrapping. */}
            <div className="min-w-0">
                <span className="block text-h3 leading-none font-semibold tabular-nums whitespace-nowrap text-foreground">
                    <AnimatedCounter value={value} format={format} />
                </span>
                {/* mt-1 (4px): half-step, figure to its own caption inside one text block. */}
                <Text variant="caption" className="mt-1 block truncate leading-none">
                    {label}
                </Text>
            </div>
        </div>
    );
}

/**
 * A wrapper that puts a blurred pool of the tone's own color behind whatever
 * it holds. Used once, under the hero gauge — the glow scaled up from a
 * 16px shadow to a 200px one, so the hero reads as the source of the light
 * the smaller tiles are catching.
 *
 * Pure decoration, so it's aria-hidden and sits behind content; it never
 * intercepts a tap.
 */
export function Glow({ tone, children, className }: { tone: Tone; children: ReactNode; className?: string }) {
    return (
        <div className={cn('relative isolate', className)}>
            <motion.span
                aria-hidden
                initial={{ opacity: 0, scale: 0.8 }}
                animate={{ opacity: 1, scale: 1 }}
                transition={{ duration: 0.9, ease: [0.16, 1, 0.3, 1], delay: 0.15 }}
                className={cn(
                    'pointer-events-none absolute top-1/2 left-1/2 -z-10 size-48 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-25 blur-3xl dark:opacity-30',
                    TONE[tone].bar,
                )}
            />
            {children}
        </div>
    );
}
