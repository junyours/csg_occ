import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import type { Role } from '@/domain/enums';

export interface RememberedAccount {
    studentNumber: string;
    firstName: string;
    lastName: string;
    photoUrl: string | null;
    role: Role;
    lastUsedAt: string;
}

const MAX_REMEMBERED = 3;

interface RememberedAccountsState {
    accounts: RememberedAccount[];
    /** Upserts by studentNumber, most-recently-used first. */
    remember: (account: Omit<RememberedAccount, 'lastUsedAt'>) => void;
    forget: (studentNumber: string) => void;
}

export const useRememberedAccountsStore = create<RememberedAccountsState>()(
    persist(
        (set) => ({
            accounts: [],
            remember: (account) =>
                set((state) => {
                    const rest = state.accounts.filter((a) => a.studentNumber !== account.studentNumber);
                    const next: RememberedAccount = { ...account, lastUsedAt: new Date().toISOString() };
                    return { accounts: [next, ...rest].slice(0, MAX_REMEMBERED) };
                }),
            forget: (studentNumber) =>
                set((state) => ({
                    accounts: state.accounts.filter((a) => a.studentNumber !== studentNumber),
                })),
        }),
        {
            name: 'occ-csg-remembered-accounts',
            // Deliberately localStorage, unlike auth.store's sessionStorage
            // for the bearer token. This never holds a token or password —
            // only non-secret identity metadata (student number, name,
            // avatar) needed to draw a "Welcome back" account card after
            // logout, the same way auth.store's session dying with the tab
            // is fine precisely because this list is what lets the login
            // screen greet a returning student without needing the old
            // session to still be alive.
            storage: createJSONStorage(() => localStorage),
        },
    ),
);
