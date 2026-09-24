import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import type { Student } from '@/domain/entities';

interface AuthState {
    token: string | null;
    student: Student | null;
    set: (token: string, student: Student) => void;
    clear: () => void;
}

export const useAuthStore = create<AuthState>()(
    persist(
        (set) => ({
            token: null,
            student: null,
            set: (token, student) => set({ token, student }),
            clear: () => set({ token: null, student: null }),
        }),
        {
            name: 'occ-csg-auth',
            // Was the zustand/persist default (localStorage), which keeps
            // the bearer token on disk indefinitely — readable by any
            // script on the page, forever, even after the browser is
            // closed and reopened days later. sessionStorage still isn't
            // XSS-proof (nothing client-side-only is), but it at least
            // dies with the tab/browser session instead of outliving it,
            // which matches the 12h server-side token expiry now set in
            // config/sanctum.php rather than undermining it.
            storage: createJSONStorage(() => sessionStorage),
        },
    ),
);
