import { useEffect } from 'react';
import { isAxiosError } from 'axios';

import { useLoginRateLimitStore } from '@/application/auth/login-rate-limit.store';

const DEFAULT_RATE_LIMIT_SECONDS = 60;

function retryAfterMs(error: unknown): number {
    if (isAxiosError(error)) {
        const header = error.response?.headers?.['retry-after'];
        const seconds = Number(header);
        if (Number.isFinite(seconds) && seconds > 0) {
            return seconds * 1000;
        }
    }
    return DEFAULT_RATE_LIMIT_SECONDS * 1000;
}

/** Call from a login 429's onError. Persists the window so it survives a
 *  refresh — see login-rate-limit.store.ts. */
export function recordLoginRateLimit(username: string, error: unknown) {
    useLoginRateLimitStore.getState().set(username, Date.now() + retryAfterMs(error));
}

/**
 * True while `username` is inside a previously-recorded rate-limit window
 * — including right after a page load, since what's persisted is the
 * window's actual end time, not just a "was limited" flag, so this checks
 * the real remaining time rather than assuming a fresh 60s on every mount.
 * Also arms a timeout to clear the limit exactly when the window ends, so
 * the button re-enables on its own without needing another failed attempt.
 */
export function useIsRateLimited(username: string): boolean {
    const limit = useLoginRateLimitStore((state) => state.limit);
    const clear = useLoginRateLimitStore((state) => state.clear);

    useEffect(() => {
        if (!limit) return;
        const remaining = limit.until - Date.now();
        if (remaining <= 0) {
            clear();
            return;
        }
        const timer = window.setTimeout(clear, remaining);
        return () => window.clearTimeout(timer);
    }, [limit, clear]);

    if (!limit || limit.username !== username) return false;
    return limit.until > Date.now();
}
