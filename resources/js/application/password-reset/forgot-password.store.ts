import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

/**
 * Where a student is in "forgot password", kept across a refresh. A student
 * asks for a code, then goes to find an officer to read it to them — long
 * enough on a phone for the tab to be reloaded or evicted. Without this they
 * would come back to a blank form, and since a new code can't be requested
 * for 5 minutes, they'd have no obvious way back to the code they already
 * asked for.
 *
 * Deliberately holds nothing secret: a student number and two clock times.
 * The reset token that follows a successful code is never persisted — a
 * refresh at that point ends the flow, which is the intended behaviour (the
 * code is spent, so there is no going back to it).
 */
export interface PendingReset {
    studentNumber: string;
    /** Epoch ms — when a new code may be requested again. */
    resendAt: number;
    /** Epoch ms — set after too many wrong guesses; verifying is blocked until then. */
    lockedUntil: number | null;
}

interface ForgotPasswordState {
    pending: PendingReset | null;
    start: (studentNumber: string, resendAt: number) => void;
    lock: (until: number) => void;
    clear: () => void;
}

export const useForgotPasswordStore = create<ForgotPasswordState>()(
    persist(
        (set) => ({
            pending: null,
            // A fresh code is a fresh set of 5 attempts, so starting always
            // wipes any earlier lock.
            start: (studentNumber, resendAt) => set({ pending: { studentNumber, resendAt, lockedUntil: null } }),
            lock: (until) =>
                set((state) => (state.pending ? { pending: { ...state.pending, lockedUntil: until } } : state)),
            clear: () => set({ pending: null }),
        }),
        {
            name: 'occ-csg-forgot-password',
            // localStorage, like the login rate-limit store next to it: the
            // limits this mirrors live on the server and don't reset on a
            // refresh or a closed tab, so neither should the countdowns.
            storage: createJSONStorage(() => localStorage),
        },
    ),
);
