import { Outlet } from 'react-router-dom';

import { AppBottomNav } from '@/presentation/layouts/app-bottom-nav';
import { PullToRefresh } from '@/presentation/components/pull-to-refresh';
import { useAuthStore } from '@/application/auth/auth.store';

export function AppLayout() {
    const student = useAuthStore((state) => state.student);

    if (!student) return null;

    return (
        // overflow-x-hidden here is a safety net, not the fix itself — it
        // stops any one stray-width element deeper in the tree from ever
        // widening the whole shell and forcing a horizontal scrollbar on
        // mobile again, the way the department cards did.
        <div className="min-h-svh overflow-x-hidden bg-background">
            {/* pb-28 clears the floating bottom nav (~4.5rem tall + its own
                bottom offset) so the last row of content is never hidden
                behind it. No top header, so pt- accounts for the device's
                safe area itself instead of a header's height. */}
            <main
                className="mx-auto max-w-5xl px-6 pb-28 sm:px-8"
                style={{ paddingTop: 'max(2rem, calc(env(safe-area-inset-top) + 1.5rem))' }}
            >
                {/* Wraps every screen behind this layout (dashboard, events,
                    students, account, reports, scan, settings, ...) with one
                    shared pull-to-refresh gesture instead of wiring it per
                    page. On mobile only — see PullToRefresh for why. */}
                <PullToRefresh>
                    <Outlet />
                </PullToRefresh>
            </main>
            <AppBottomNav student={student} />
        </div>
    );
}
