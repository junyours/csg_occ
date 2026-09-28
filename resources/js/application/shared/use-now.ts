import { useEffect, useState } from 'react';

/**
 * `Date.now()`, re-read every `intervalMs` — drives countdowns. Every
 * component that calls this re-renders on the tick, so keep it in the
 * smallest component that actually shows a clock.
 */
export function useNow(intervalMs = 1000): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), intervalMs);
        return () => window.clearInterval(id);
    }, [intervalMs]);

    return now;
}
