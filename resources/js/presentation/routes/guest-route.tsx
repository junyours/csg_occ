import { Navigate, Outlet } from 'react-router-dom';

import { useAuthStore } from '@/application/auth/auth.store';

/**
 * Wraps routes that only make sense for a signed-out visitor (login).
 * Without this, an authenticated user hitting /login directly just sees
 * the login form again instead of being sent back into the app.
 */
export function GuestRoute() {
    const token = useAuthStore((state) => state.token);
    const student = useAuthStore((state) => state.student);

    // A session that hasn't accepted terms yet stays on /login rather than
    // being redirected away — LoginPage itself notices this on mount and
    // re-opens the acceptance modal, so refreshing mid-flow doesn't skip it.
    if (token && student && !student.hasAcceptedTerms) {
        return <Outlet />;
    }

    if (token && student) {
        return <Navigate to={student.mustChangePassword ? '/change-password' : '/dashboard'} replace />;
    }

    return <Outlet />;
}
