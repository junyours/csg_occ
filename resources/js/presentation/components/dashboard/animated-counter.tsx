import { useEffect, useRef } from 'react';
import { animate, useReducedMotion } from 'framer-motion';

interface AnimatedCounterProps {
    value: number;
    format?: (n: number) => string;
    className?: string;
    duration?: number;
    delay?: number;
}

/**
 * Counts up from 0 to `value` once on mount (and again whenever `value`
 * changes, e.g. a fresh dashboard fetch). Writes straight to the DOM node
 * via onUpdate rather than useState, so a fast-ticking count doesn't
 * trigger a React re-render on every frame.
 *
 * Jumps straight to the final value with no animation when the user has
 * requested reduced motion.
 */
export function AnimatedCounter({ value, format, className, duration = 1.1, delay = 0 }: AnimatedCounterProps) {
    const ref = useRef<HTMLSpanElement>(null);
    const shouldReduceMotion = useReducedMotion();

    useEffect(() => {
        const node = ref.current;
        if (!node) return;

        const render = format ?? ((n: number) => String(Math.round(n)));

        if (shouldReduceMotion) {
            node.textContent = render(value);
            return;
        }

        const controls = animate(0, value, {
            duration,
            delay,
            ease: [0.16, 1, 0.3, 1],
            onUpdate(latest) {
                node.textContent = render(latest);
            },
        });

        return () => controls.stop();
    }, [value, format, duration, delay, shouldReduceMotion]);

    return (
        <span ref={ref} className={className}>
            {format ? format(0) : '0'}
        </span>
    );
}
