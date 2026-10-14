import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * A horizontally scrolling tab strip that says so. A bare overflow-x-auto
 * row hides everything past the edge with no hint it's there — "Day 6" can
 * sit one swipe away and nobody would know. This adds the usual cues:
 *
 *   - a fade on whichever edge still has tabs hidden behind it, and
 *   - the selected tab is scrolled into view when it changes, so the
 *     active day is never the one that's off-screen.
 *
 * `className` goes on the scrolling row itself (gap, border, padding);
 * `activeKey` is anything that changes when the selected tab does.
 */
export function ScrollableTabList({
    className,
    activeKey,
    children,
}: {
    className?: string;
    activeKey?: string | number | boolean;
    children: ReactNode;
}) {
    const scrollerRef = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ left: false, right: false });

    const update = useCallback(() => {
        const el = scrollerRef.current;
        if (!el) return;
        const left = el.scrollLeft > 1;
        const right = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
        // Same values → return the same object so this can't loop a render.
        setEdges((prev) => (prev.left === left && prev.right === right ? prev : { left, right }));
    }, []);

    useEffect(() => {
        const el = scrollerRef.current;
        if (!el) return;
        update();
        el.addEventListener('scroll', update, { passive: true });
        const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(update) : null;
        observer?.observe(el);
        return () => {
            el.removeEventListener('scroll', update);
            observer?.disconnect();
        };
    }, [update]);

    // Tabs can be added/removed without the container resizing (a new day).
    useEffect(update, [children, update]);

    // Keep the selected tab on screen. Scrolls the strip only, never the page.
    useEffect(() => {
        const el = scrollerRef.current;
        const selected = el?.querySelector<HTMLElement>('[aria-selected="true"]');
        if (!el || !selected) return;
        const target = selected.offsetLeft - (el.clientWidth - selected.offsetWidth) / 2;
        el.scrollTo({ left: Math.max(0, target), behavior: 'smooth' });
    }, [activeKey]);

    return (
        <div className="relative">
            <div
                ref={scrollerRef}
                role="tablist"
                className={cn(
                    // relative: makes this the offsetParent the scroll-into-view maths uses.
                    // The scrollbar is hidden — the edge fades replace it.
                    // pb-px: the active tab's -mb-px underline overhangs by 1px and
                    // overflow-y-hidden would clip it without this.
                    'relative overflow-x-auto overflow-y-hidden pb-px [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                    className,
                )}
            >
                {children}
            </div>

            {edges.left && <EdgeFade side="left" />}
            {edges.right && <EdgeFade side="right" />}
        </div>
    );
}

function EdgeFade({ side }: { side: 'left' | 'right' }) {
    return (
        <div
            aria-hidden
            className={cn(
                'pointer-events-none absolute inset-y-0 w-10 from-card via-card/70 to-transparent',
                side === 'left' ? 'left-0 bg-gradient-to-r' : 'right-0 bg-gradient-to-l',
            )}
        />
    );
}
