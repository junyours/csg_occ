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
            // localStorage, so the login is shared by every tab of this
            // browser — like any normal site. Opening csg.occph.com in a
            // new tab lands straight on the dashboard (see GuestRoute),
            // and only one account can be signed in per browser at a time.
            // It used to be sessionStorage, which is private to each tab:
            // a new tab looked signed out and could sign into a different
            // account alongside the first.
            //
            // A token that has outlived the server-side 12h expiry is
            // harmless: the first API call answers 401 and the http
            // client clears this store (see infrastructure/http/client.ts).
            storage: createJSONStorage(() => localStorage),
        },
    ),
);

// localStorage is shared, but each tab keeps its own in-memory copy of the
// store. Without this, signing in or out in one tab leaves the others showing
// the old state until they reload. The `storage` event fires in every OTHER
// tab when this key changes, so they re-read it and follow along.
if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
        if (event.key === 'occ-csg-auth') {
            void useAuthStore.persist.rehydrate();
        }
    });
}
