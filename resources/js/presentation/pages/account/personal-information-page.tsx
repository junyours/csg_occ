import { useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { toast } from 'sonner';

import { Camera, GraduationCap, Hash, IdCard, UserRound } from 'lucide-react';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useAuthStore } from '@/application/auth/auth.store';
import { useUpdateStudentPhoto } from '@/application/students/use-update-student-photo';
import { AvatarPhotoEditor } from '@/presentation/components/avatar-photo-editor';
import { Heading, Text } from '@/presentation/components/typography';
import { Tile } from '@/presentation/components/tile';
import type { Tone } from '@/presentation/components/tone';
import type { LucideIcon } from 'lucide-react';

// 5MB is generous for what this actually needs to be: a single headshot,
// not a full-resolution camera photo. A modern phone's default JPEG photo
// is typically 2-6MB, so this rarely blocks someone who picked a normal
// photo straight from their camera roll — but it still keeps a raw/HEIC
// export or a screenshot of a whole gallery from slipping through. Mirrors
// the backend ceiling in UpdateStudentPhotoRequest (max:5120 KB) exactly,
// so a file that passes this check never gets rejected by the server for
// size a second time.
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

function initials(firstName: string, lastName: string) {
    return `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase();
}

interface DetailRowProps {
    label: string;
    value: string;
    Icon: LucideIcon;
    tone: Tone;
}

/**
 * Read-only fact about the student. Laid out as label-above-value rather
 * than label-left/value-right: a long full name in a right-aligned column
 * was truncating on a phone, and the thing being truncated was the value,
 * which is the only part worth reading.
 */
function DetailRow({ label, value, Icon, tone }: DetailRowProps) {
    return (
        <div className="flex items-center gap-4 rounded-2xl border border-border bg-card p-4">
            <Tile tone={tone} variant="soft" Icon={Icon} />
            <div className="min-w-0">
                <Text variant="caption" className="leading-none">
                    {label}
                </Text>
                <Text className="mt-2 truncate font-medium leading-none">{value}</Text>
            </div>
        </div>
    );
}

export function PersonalInformationPage() {
    const student = useAuthStore((state) => state.student);
    const token = useAuthStore((state) => state.token);
    const setSession = useAuthStore((state) => state.set);
    const updatePhoto = useUpdateStudentPhoto();

    const fileInputRef = useRef<HTMLInputElement>(null);
    const [pendingFile, setPendingFile] = useState<File | null>(null);

    if (!student) return null;

    function handleFileChange(event: React.ChangeEvent<HTMLInputElement>) {
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
                description: 'Please reduce the image size to 5MB or less (crop it, or lower the camera/export quality) and try again.',
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
                    if (token) setSession(token, { ...student, photoPath: updated.photoPath, photoUrl: updated.photoUrl });
                    toast.success('Photo updated.');
                    setPendingFile(null);
                },
                onError: (error) => {
                    if (isAxiosError(error) && error.response?.status === 423) {
                        toast.error('Photo can’t be changed while an attendance session you’re eligible for is ongoing.');
                        return;
                    }
                    toast.error('Could not update your photo. Please try again.');
                },
            },
        );
    }

    const fullName = [student.firstName, student.middleName, student.lastName, student.suffix]
        .filter(Boolean)
        .join(' ');
    const department = student.departmentName ?? `Department #${student.departmentId}`;
    const yearAndSection = [student.yearLevel, student.section].filter(Boolean).join(' — ') || '—';

    return (
        <div className="mx-auto max-w-md space-y-8">
            <div className="space-y-2">
                <Heading level="h1">Personal information</Heading>
                <Text variant="small">
                    Your details on file. Name and department are set by your school administrator — contact them if
                    anything here needs correcting.
                </Text>
            </div>

            <div className="flex items-center gap-4 rounded-3xl border bg-card p-4">
                <Avatar className="size-20 shadow-lg shadow-violet-500/20 ring-4 ring-violet-500/10">
                    {student.photoUrl ? <AvatarImage src={student.photoUrl} alt={`${student.firstName} ${student.lastName}`} /> : null}
                    <AvatarFallback className="bg-violet-500/10 text-lg text-violet-600 dark:text-violet-400">
                        {initials(student.firstName, student.lastName)}
                    </AvatarFallback>
                </Avatar>
                <div className="space-y-2">
                    <Button type="button" variant="outline" size="sm" className="gap-2" onClick={() => fileInputRef.current?.click()}>
                        <Camera className="size-4" />
                        Change photo
                    </Button>
                    <Text variant="caption" className="block">
                        JPEG, PNG, or WEBP. Max 5MB.
                    </Text>
                </div>
                <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPTED_TYPES.join(',')}
                    className="hidden"
                    onChange={handleFileChange}
                />
            </div>

            <div className="space-y-2">
                <DetailRow label="Full name" value={fullName} Icon={UserRound} tone="violet" />
                <DetailRow label="Student number" value={student.studentNumber} Icon={Hash} tone="sky" />
                <DetailRow label="Department" value={department} Icon={IdCard} tone="orange" />
                <DetailRow label="Year & section" value={yearAndSection} Icon={GraduationCap} tone="emerald" />
            </div>

            {pendingFile && (
                <AvatarPhotoEditor
                    file={pendingFile}
                    onCancel={() => setPendingFile(null)}
                    onConfirm={handleConfirmCrop}
                    isSaving={updatePhoto.isPending}
                />
            )}
        </div>
    );
}
