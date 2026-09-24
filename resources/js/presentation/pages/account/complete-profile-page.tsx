import { useRef, useState, type ChangeEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { isAxiosError } from 'axios';
import { Camera } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/application/auth/auth.store';
import { useUpdateStudentPhoto } from '@/application/students/use-update-student-photo';
import { httpAuthRepository } from '@/infrastructure/auth/auth.repository.http';
import { AuthLayout } from '@/presentation/layouts/auth-layout';
import { AvatarPhotoEditor } from '@/presentation/components/avatar-photo-editor';
import { Text } from '@/presentation/components/typography';
import { UserAvatar } from '@/presentation/components/user-avatar';
import { TONE } from '@/presentation/components/tone';
import { cn } from '@/lib/utils';

// Mirrors personal-information-page.tsx's own limits exactly, so a file
// that passes this check never gets rejected by the backend a second
// time (UpdateStudentPhotoRequest: max:5120 KB).
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

/**
 * The photo-gate equivalent of ChangePasswordPage. ProtectedRoute
 * redirects a student with no photoPath on file here, exactly the way it
 * already redirects one with mustChangePassword still true to
 * /change-password — same shape: one required action standing between
 * login and the rest of the app, plus a sign-out escape hatch for
 * "wrong account", and nothing else reachable until it's done.
 *
 * Replaces the earlier dashboard-toast + locked-nav-item approach (see
 * the removed usePhotoReminderToast/photo-reminder.ts): those could only
 * ever be a client-side reminder that a hard refresh silently lost. This
 * is enforced the same way the password gate is — by ProtectedRoute
 * never rendering anything else, backed by EnsurePhotoHasBeenUploaded
 * refusing the same routes server-side.
 */
export function CompleteProfilePage() {
    const navigate = useNavigate();
    const student = useAuthStore((state) => state.student);
    const token = useAuthStore((state) => state.token);
    const setSession = useAuthStore((state) => state.set);
    const clearSession = useAuthStore((state) => state.clear);
    const updatePhoto = useUpdateStudentPhoto();

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [pendingFile, setPendingFile] = useState<File | null>(null);
    const [isSigningOut, setIsSigningOut] = useState(false);

    if (!student) return null;

    function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        // Always reset the input so choosing the same file twice in a row
        // (e.g. cancel, then reselect) still fires onChange.
        event.target.value = '';
        if (!file) return;

        if (!ACCEPTED_TYPES.includes(file.type)) {
            toast.error('Please choose a JPEG, PNG, or WEBP image.');
            return;
        }
        if (file.size > MAX_FILE_BYTES) {
            const currentMb = (file.size / (1024 * 1024)).toFixed(1);
            toast.error(`That photo is ${currentMb}MB — the limit is 5MB.`, {
                description:
                    'Please reduce the image size to 5MB or less (crop it, or lower the camera/export quality) and try again.',
            });
            return;
        }
        setPendingFile(file);
    }

    function handleConfirmCrop(blob: Blob) {
        if (!student) return;
        const croppedFile = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });

        updatePhoto.mutate(
            { studentId: student.id, file: croppedFile },
            {
                onSuccess: (updated) => {
                    // Writes the new photoPath into the auth store — the
                    // same store ProtectedRoute reads needsPhotoUpload
                    // from — so the very next render clears the redirect
                    // and the student lands on /dashboard normally.
                    if (token) setSession(token, { ...student, photoPath: updated.photoPath, photoUrl: updated.photoUrl });
                    toast.success('Photo uploaded.');
                    navigate('/dashboard', { replace: true });
                },
                onError: (error) => {
                    // Surface the backend's actual reason where it has one
                    // (e.g. blocked while an eligible session is ongoing)
                    // instead of a generic message for every case.
                    const message =
                        isAxiosError(error) && typeof error.response?.data?.message === 'string'
                            ? error.response.data.message
                            : 'Could not upload your photo. Please try again.';
                    toast.error(message);
                },
            },
        );
    }

    // Doesn't skip the photo requirement itself (photo_path stays null —
    // the guard still forces this screen on the next login). It only lets
    // someone who signed into the wrong account back out to /login
    // without being stuck here, by ending this session properly. Mirrors
    // ChangePasswordPage's own handleSignOut exactly.
    async function handleSignOut() {
        setIsSigningOut(true);
        try {
            await httpAuthRepository.logout();
        } finally {
            clearSession();
            navigate('/login', { replace: true });
        }
    }

    return (
        <AuthLayout
            title="Add your verification photo"
            description="Officers check you in by matching your face to this photo, so it has to be on file before you can use the app."
        >
            <div className="space-y-4">
                {/* Same violet-washed identity card PasswordStepView shows
                    before sign-in — confirms *whose* photo this is, and
                    keeps this gate reading as an identity moment instead of
                    a bare upload form. UserAvatar falls back to initials
                    here, since photoPath is exactly what this screen exists
                    to collect. */}
                <div className={cn('flex items-center gap-4 rounded-2xl border p-4', TONE.violet.wash)}>
                    <UserAvatar student={student} className="size-11 ring-2 ring-violet-500/20" />
                    <div className="min-w-0 flex-1">
                        <Text className="truncate font-medium leading-tight">
                            {student.firstName} {student.lastName}
                        </Text>
                        <Text variant="caption" className="truncate font-mono tracking-wide">
                            {student.studentNumber}
                        </Text>
                    </div>
                </div>

                <Button
                    type="button"
                    className="w-full gap-2"
                    onClick={() => fileInputRef.current?.click()}
                    disabled={updatePhoto.isPending}
                >
                    <Camera className="size-4" />
                    Choose a photo
                </Button>
                <Text variant="caption" className="block text-center">
                    JPEG, PNG, or WEBP. Max 5MB.
                </Text>
                <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPTED_TYPES.join(',')}
                    className="hidden"
                    onChange={handleFileChange}
                />

                <button
                    type="button"
                    onClick={handleSignOut}
                    disabled={isSigningOut}
                    className="w-full text-center text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline disabled:pointer-events-none disabled:opacity-50"
                >
                    {isSigningOut ? 'Signing out…' : 'Not you? Sign out'}
                </button>
            </div>

            {pendingFile && (
                <AvatarPhotoEditor
                    file={pendingFile}
                    onCancel={() => setPendingFile(null)}
                    onConfirm={handleConfirmCrop}
                    isSaving={updatePhoto.isPending}
                />
            )}
        </AuthLayout>
    );
}
