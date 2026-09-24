import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

import type { Student } from '@/domain/entities';
import { needsPhotoUpload } from '@/domain/student-photo';
import { dismissPhotoReminder, showPhotoReminder } from '@/presentation/components/account/photo-reminder';

/**
 * Mounted once, in AppLayout, so it covers every authenticated screen
 * rather than just the dashboard. In practice that still means "shows up
 * the moment the student lands on their dashboard" — '/' → '/dashboard'
 * is where a first login always lands — but it also means the toast
 * doesn't vanish the instant they tap away to another tab, which a
 * dashboard-only effect would do every time it unmounted.
 *
 * Re-evaluates whenever `student` changes, so uploading a photo on the
 * Personal information screen (which writes the new `photoPath` into the
 * auth store) dismisses this on the next render — no manual wiring needed
 * between the two screens.
 */
export function usePhotoReminderToast(student: Student | null) {
    const navigate = useNavigate();

    useEffect(() => {
        if (!needsPhotoUpload(student)) {
            dismissPhotoReminder();
            return;
        }

        showPhotoReminder(navigate);
    }, [student, navigate]);
}
