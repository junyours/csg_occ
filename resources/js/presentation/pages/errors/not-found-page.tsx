import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';

/**
 * Unmatched routes never get their own dead-end screen — they bounce the
 * user straight back to where they were (or the dashboard, if there's no
 * "back" to go to) with a toast explaining why. A stray/typo'd URL should
 * feel like it was rejected, not like it took you somewhere new.
 */
export function NotFoundPage() {
    const navigate = useNavigate();

    useEffect(() => {
        toast.error("That page doesn't exist.");

        if (window.history.length > 1) {
            navigate(-1);
        } else {
            navigate('/dashboard', { replace: true });
        }
        // Intentionally run once on mount only.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return null;
}
