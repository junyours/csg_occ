import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';

import { httpServerTimeRepository } from '@/infrastructure/server-time/server-time.repository.http';

export const SERVER_TIME_QUERY_KEY = ['server-time'];

/**
 * The offset (ms) between this application server's clock and the
 * viewer's device clock, sampled from GET /server-time.
 *
 * Every place in this app that gates an action on "has this moment been
 * reached yet" — enabling a session's Start button, in particular — needs
 * to ask that against a clock the viewer can't change. The backend already
 * enforces this for real: StartSession and ScanAttendance both compare
 * against their own Carbon::now(), this application server's system
 * clock, never a timestamp the client sends. This hook lets the UI *show*
 * the same answer up front (a disabled button, a countdown) instead of
 * only finding out after a rejected request — but it's a courtesy, not
 * the security boundary. Refetched every few minutes so a long-lived tab
 * (an event screen left open for hours) doesn't drift.
 */
export function useServerTimeOffsetMs(): number {
    const query = useQuery({
        queryKey: SERVER_TIME_QUERY_KEY,
        queryFn: async () => {
            const requestedAt = Date.now();
            const serverNow = await httpServerTimeRepository.now();
            // Halving the round-trip time is a reasonable stand-in for
            // one-way latency on a phone/campus wifi connection — plenty
            // good enough for a "has 2:45 PM arrived yet" check, which
            // doesn't need sub-second precision.
            const oneWayLatency = (Date.now() - requestedAt) / 2;

            return serverNow.getTime() + oneWayLatency - Date.now();
        },
        staleTime: 5 * 60 * 1000,
        refetchInterval: 5 * 60 * 1000,
    });

    // Defaults to 0 (i.e. trusts the device clock) only until the first
    // sync resolves, moments after the screen loads — that brief window
    // could still fool the *button's* enabled state, but never the actual
    // Start/Scan request, which is checked server-side regardless.
    return query.data ?? 0;
}

/**
 * The current instant, corrected by the server/device offset above, and
 * re-read every `intervalMs` so anything gated on it (a disabled-until-X
 * button, a countdown) updates live without the viewer refreshing.
 */
export function useTrustedNow(intervalMs = 1000): Date {
    const offsetMs = useServerTimeOffsetMs();
    const [tick, setTick] = useState(() => Date.now());

    useEffect(() => {
        const id = setInterval(() => setTick(Date.now()), intervalMs);
        return () => clearInterval(id);
    }, [intervalMs]);

    return new Date(tick + offsetMs);
}
