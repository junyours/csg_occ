import { Navigate, Outlet } from 'react-router-dom';

import { useAuthStore } from '@/application/auth/auth.store';
import type { Role } from '@/domain/enums';

interface RoleRouteProps {
    /** Roles allowed to open the routes nested under this guard. */
    roles: Role[];
    /** Where everyone else is sent. Defaults to Settings, the screen these entries live under. */
    redirectTo?: string;
}

/**
 * Route-level role gate, nested inside ProtectedRoute (so a student is
 * always present by the time this renders). It saves the round trip and
 * keeps the guard next to the route table; the API's policies still
 * answer 403 for anyone who gets past it, so this is never the only lock.
 */
export function RoleRoute({ roles, redirectTo = '/settings' }: RoleRouteProps) {
    const student = useAuthStore((state) => state.student);

    if (!student || !roles.includes(student.role)) {
        return <Navigate to={redirectTo} replace />;
    }

    return <Outlet />;
}
