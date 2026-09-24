import type { NavigateFunction } from 'react-router-dom';
import { toast } from 'sonner';

/**
 * Fixed id so every call below updates the same toast instead of stacking
 * a new one on top — whether it was raised from the dashboard on login,
 * from tapping the locked "My QR" nav item, or from landing on /profile
 * directly.
 */
export const PHOTO_REMINDER_TOAST_ID = 'missing-photo-reminder';

const DESCRIPTION =
    "Officers check you in by matching your face to this photo, so My QR stays locked until it's on file. " +
    "You can't upload one once an attendance session has started, so add it now — before your next session opens.";

/**
 * Raises (or refreshes) the reminder. `duration: Infinity` plus
 * `dismissible: false` are both deliberate: a student swiping this away or
 * it timing out would leave them thinking they're set up for their next
 * session when they're not. The only way it goes away is `dismissPhotoReminder`,
 * called once `student.photoPath` is actually set.
 */
export function showPhotoReminder(navigate: NavigateFunction) {
    toast.warning('Upload your photo to unlock My QR', {
        id: PHOTO_REMINDER_TOAST_ID,
        duration: Infinity,
        dismissible: false,
        description: DESCRIPTION,
        action: {
            label: 'Complete profile',
            onClick: () => navigate('/account/personal-information'),
        },
    });
}

export function dismissPhotoReminder() {
    toast.dismiss(PHOTO_REMINDER_TOAST_ID);
}
