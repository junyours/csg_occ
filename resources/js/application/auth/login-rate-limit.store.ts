import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

interface LoginRateLimit {
    username: string;
    /** Epoch ms — when the server-side throttle window this mirrors ends. */
    until: number;
}

interface LoginRateLimitState {
    limit: LoginRateLimit | null;
    set: (username: string, until: number) => void;
    clear: () => void;
}

export const useLoginRateLimitStore = create<LoginRateLimitState>()(
    persist(
        (set) => ({
            limit: null,
            set: (username, until) => set({ limit: { username, until } }),
            clear: () => set({ limit: null }),
        }),
        {
            name: 'occ-csg-login-rate-limit',
            // localStorage, not sessionStorage. The throttle this mirrors
            // (RateLimiter::for('login') in AppServiceProvider, keyed on
            // IP + username) is enforced server-side and doesn't reset on
            // a page refresh or a closed tab, so neither should the button
            // that reflects it — otherwise a refresh just lets the student
            // burn another attempt against a limiter that's still active.
            storage: createJSONStorage(() => localStorage),
        },
    ),
);
