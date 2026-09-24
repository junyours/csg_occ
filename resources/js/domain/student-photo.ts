import { Role } from '@/domain/enums';
import type { Student } from '@/domain/entities';

/**
 * Whether this student still needs to upload their verification photo.
 *
 * Only ever true for the Student role — officers/admins aren't the ones
 * being scanned in at the gate, so nothing gates them on this. Single
 * source of truth so the dashboard reminder, the bottom nav, and the QR
 * screen itself all agree on exactly when a student is "missing a photo"
 * instead of drifting into three slightly different checks.
 */
export function needsPhotoUpload(student: Student | null | undefined): boolean {
    if (!student) return false;
    return student.role === Role.Student && !student.photoPath;
}
