import { Navigate, Outlet, useLocation } from 'react-router-dom';

import { useAuthStore } from '@/application/auth/auth.store';
import { needsPhotoUpload } from '@/domain/student-photo';

export function ProtectedRoute() {
    const token = useAuthStore((state) => state.token);
    const student = useAuthStore((state) => state.student);
    const location = useLocation();

    if (!token || !student) {
        return <Navigate to="/login" state={{ from: location }} replace />;
    }

    // Client-side gate mirrors the server-side middleware — the API still
    // rejects gated endpoints while must_change_password is true, this just
    // saves the user a round trip.
    if (student.mustChangePassword) {
        if (location.pathname !== '/change-password') {
            return <Navigate to="/change-password" replace />;
        }
        // Still needs to change the password (we're already on that page) —
        // stop here so the photo check below never runs. Without this,
        // landing on /change-password satisfies "pathname !== /complete-profile"
        // and gets bounced straight past the password screen.
        return <Outlet />;
    }

    // Same treatment, same order (password first, then photo) — matches
    // routes/api.php's password.changed group nesting photo.uploaded
    // inside it. Only reached once mustChangePassword is false, so this
    // can never fire before the password gate is cleared.
    // EnsurePhotoHasBeenUploaded still rejects gated endpoints
    // server-side; this just saves the round trip, and replaces the
    // earlier dashboard-toast + locked-nav-item approach with the same
    // hard redirect the password gate already uses.
    if (needsPhotoUpload(student) && location.pathname !== '/complete-profile') {
        return <Navigate to="/complete-profile" replace />;
    }

    return <Outlet />;
}
