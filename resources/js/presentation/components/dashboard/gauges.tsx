import type { ReactNode } from 'react';
import { motion, useReducedMotion } from 'framer-motion';

interface RadialGaugeProps {
    /** 0–100 */
    value: number;
    /** Diameter in px. Keep it on the 8-point scale (see spacing.ts) — 176, 144, … */
    size?: number;
    strokeWidth?: number;
    /** Tailwind stroke-* class for the filled arc. Ignored when `gradient` is set. */
    colorClassName?: string;
    /** Two hex/CSS colors for a gamified gradient arc, e.g. ['#a78bfa', '#f472b6']. */
    gradient?: [string, string];
    trackClassName?: string;
    delay?: number;
    children?: ReactNode;
}

/**
 * A single-value progress ring — the hero "score" moment for the Student
 * and Officer dashboards. Content passed as children is centered inside
 * the ring (typically the animated percentage + a tier label). Pops in
 * with a small spring scale on mount so the hero score feels earned
 * rather than just appearing.
 */
export function RadialGauge({
    value,
    size = 176,
    strokeWidth = 16,
    colorClassName = 'stroke-violet-500 dark:stroke-violet-400',
    gradient,
    trackClassName = 'stroke-black/5 dark:stroke-white/10',
    delay = 0.1,
    children,
}: RadialGaugeProps) {
    const shouldReduceMotion = useReducedMotion();
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const clamped = Math.min(Math.max(value, 0), 100);
    const offset = circumference * (1 - clamped / 100);
    const gradientId = 'radial-gauge-gradient';

    return (
        <motion.div
            className="relative inline-flex shrink-0 items-center justify-center"
            style={{ width: size, height: size }}
            initial={shouldReduceMotion ? undefined : { scale: 0.85, opacity: 0 }}
            animate={{ scale: 1, opacity: 1 }}
            transition={{ type: 'spring', stiffness: 220, damping: 18, delay: shouldReduceMotion ? 0 : delay * 0.5 }}
        >
            <svg width={size} height={size} className="-rotate-90">
                {gradient && (
                    <defs>
                        <linearGradient id={gradientId} x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stopColor={gradient[0]} />
                            <stop offset="100%" stopColor={gradient[1]} />
                        </linearGradient>
                    </defs>
                )}
                <circle cx={size / 2} cy={size / 2} r={radius} strokeWidth={strokeWidth} fill="none" className={trackClassName} />
                <motion.circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    strokeWidth={strokeWidth}
                    fill="none"
                    strokeLinecap="round"
                    stroke={gradient ? `url(#${gradientId})` : undefined}
                    className={gradient ? undefined : colorClassName}
                    strokeDasharray={circumference}
                    initial={{ strokeDashoffset: circumference }}
                    animate={{ strokeDashoffset: shouldReduceMotion ? offset : offset }}
                    transition={{ duration: shouldReduceMotion ? 0 : 1.2, delay: shouldReduceMotion ? 0 : delay, ease: [0.16, 1, 0.3, 1] }}
                />
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">{children}</div>
        </motion.div>
    );
}

interface Segment {
    value: number;
    className: string;
}

interface SegmentedRingProps {
    segments: Segment[];
    size?: number;
    strokeWidth?: number;
    trackClassName?: string;
    children?: ReactNode;
}

/**
 * A multi-segment donut — used for "distribution" reads (e.g. Present /
 * Late / Absent share of everything tracked) rather than a single score.
 * Each segment is drawn as its own circle sharing the ring, offset to
 * start exactly where the previous one ended.
 */
export function SegmentedRing({ segments, size = 176, strokeWidth = 16, trackClassName = 'stroke-black/5 dark:stroke-white/10', children }: SegmentedRingProps) {
    const shouldReduceMotion = useReducedMotion();
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    let cumulativeFraction = 0;

    return (
        <div className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={radius} strokeWidth={strokeWidth} fill="none" className={trackClassName} />
                {total > 0 &&
                    segments
                        .filter((segment) => segment.value > 0)
                        .map((segment, index) => {
                            const fraction = segment.value / total;
                            const arcLength = circumference * fraction;
                            const dashOffset = -cumulativeFraction * circumference;
                            cumulativeFraction += fraction;

                            return (
                                <motion.circle
                                    key={index}
                                    cx={size / 2}
                                    cy={size / 2}
                                    r={radius}
                                    strokeWidth={strokeWidth}
                                    fill="none"
                                    className={segment.className}
                                    strokeDasharray={`${arcLength} ${circumference - arcLength}`}
                                    initial={{ strokeDashoffset: 0, opacity: 0 }}
                                    animate={{ strokeDashoffset: dashOffset, opacity: 1 }}
                                    transition={{
                                        duration: shouldReduceMotion ? 0 : 0.9,
                                        delay: shouldReduceMotion ? 0 : 0.15 + index * 0.12,
                                        ease: [0.16, 1, 0.3, 1],
                                    }}
                                />
                            );
                        })}
            </svg>
            <div className="absolute inset-0 flex flex-col items-center justify-center">{children}</div>
        </div>
    );
}
