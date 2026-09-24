import { useCallback, useRef, useState, type ReactNode, type TouchEvent as ReactTouchEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Loader2, RefreshCw } from 'lucide-react';

import { cn } from '@/lib/utils';

/**
 * Pull-down-to-refresh, mobile only — mirrors what a native app's list
 * view does: pull the whole screen down past a threshold, get a spinner,
 * everything on screen refetches.
 *
 * Deliberately touch-only (gated on `(pointer: coarse)`) rather than also
 * wiring up mouse-drag, since a desktop user dragging inside the page is
 * almost always selecting text or dragging a control, not asking for a
 * refresh — attaching this to mouse events there would just be a source
 * of bugs for no real benefit.
 *
 * "Refresh" here means re-running every react-query hook currently
 * mounted on screen (`refetchQueries({ type: 'active' })`), which is the
 * same generic behavior for every page — no per-screen wiring needed.
 */

const PULL_THRESHOLD = 72;
const MAX_PULL = 120;
const RESISTANCE = 0.55;
const isCoarsePointer = () =>
    typeof window !== 'undefined' && window.matchMedia?.('(pointer: coarse)').matches;

export function PullToRefresh({ children, className }: { children: ReactNode; className?: string }) {
    const queryClient = useQueryClient();
    const [pull, setPull] = useState(0);
    const [refreshing, setRefreshing] = useState(false);

    // Refs, not state, for anything read/written every touchmove frame —
    // avoids a re-render per pixel of drag.
    const startY = useRef<number | null>(null);
    const tracking = useRef(false);
    const containerRef = useRef<HTMLDivElement>(null);

    const atTop = () => window.scrollY <= 0;

    const handleTouchStart = useCallback((event: ReactTouchEvent<HTMLDivElement>) => {
        if (!isCoarsePointer() || refreshing) return;
        if (!atTop()) return;
        startY.current = event.touches[0].clientY;
        tracking.current = true;
    }, [refreshing]);

    const handleTouchMove = useCallback(
        (event: ReactTouchEvent<HTMLDivElement>) => {
            if (!tracking.current || startY.current === null) return;

            // Someone scrolled the page back up before releasing — bail
            // out cleanly instead of fighting the browser's own scroll.
            if (!atTop()) {
                tracking.current = false;
                startY.current = null;
                setPull(0);
                return;
            }

            const deltaY = event.touches[0].clientY - startY.current;
            if (deltaY <= 0) {
                setPull(0);
                return;
            }

            // Only take over the gesture once it's clearly a vertical
            // pull, so a diagonal/horizontal swipe (e.g. a carousel)
            // isn't hijacked.
            event.preventDefault();
            setPull(Math.min(deltaY * RESISTANCE, MAX_PULL));
        },
        [],
    );

    const handleTouchEnd = useCallback(async () => {
        if (!tracking.current) return;
        tracking.current = false;
        startY.current = null;

        if (pull >= PULL_THRESHOLD) {
            setRefreshing(true);
            setPull(PULL_THRESHOLD);
            try {
                await queryClient.refetchQueries({ type: 'active' });
            } finally {
                setRefreshing(false);
                setPull(0);
            }
        } else {
            setPull(0);
        }
    }, [pull, queryClient]);

    const progress = Math.min(pull / PULL_THRESHOLD, 1);

    return (
        <div
            ref={containerRef}
            className={cn('relative', className)}
            onTouchStart={handleTouchStart}
            onTouchMove={handleTouchMove}
            onTouchEnd={handleTouchEnd}
            onTouchCancel={handleTouchEnd}
        >
            <div
                aria-hidden={!refreshing && pull === 0}
                className="pointer-events-none absolute inset-x-0 top-0 flex justify-center overflow-hidden"
                style={{
                    height: pull,
                    opacity: refreshing ? 1 : progress,
                    transition: tracking.current ? 'none' : 'height 200ms ease-out, opacity 200ms ease-out',
                }}
            >
                <div className="flex items-end pb-2">
                    {refreshing ? (
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    ) : (
                        <RefreshCw
                            className="size-5 text-muted-foreground"
                            style={{ transform: `rotate(${progress * 180}deg)` }}
                        />
                    )}
                </div>
            </div>
            <div
                style={{
                    transform: `translateY(${pull}px)`,
                    transition: tracking.current ? 'none' : 'transform 200ms ease-out',
                }}
            >
                {children}
            </div>
        </div>
    );
}
