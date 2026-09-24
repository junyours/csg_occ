import { describe, expect, it } from 'vitest';

import { Role } from '@/domain/enums';
import type { Student } from '@/domain/entities';
import { needsPhotoUpload } from '@/domain/student-photo';

/**
 * `needsPhotoUpload` is the single check the dashboard reminder toast, the
 * bottom nav's locked "My QR" item, and the QR page's blocking screen all
 * call — so a regression here would silently break all three gates at
 * once. Covering it directly (no React, no router, no toast library)
 * keeps that guarantee cheap to run and cheap to trust.
 */

function makeStudent(overrides: Partial<Student> = {}): Student {
    return {
        id: 1,
        studentNumber: '2023-00001',
        lastName: 'Dela Cruz',
        firstName: 'Juan',
        middleName: null,
        suffix: null,
        departmentId: 1,
        departmentName: 'College of Computer Studies',
        departmentCode: 'CCS',
        major: null,
        yearLevel: '2',
        section: 'A',
        dateEnrolled: null,
        role: Role.Student,
        scAdminDepartmentId: null,
        officerEventId: null,
        semesterId: null,
        photoPath: null,
        photoUrl: null,
        mustChangePassword: false,
        hasAcceptedTerms: true,
        ...overrides,
    };
}

describe('needsPhotoUpload', () => {
    it('is true for a student with no photo on file', () => {
        const student = makeStudent({ photoPath: null });
        expect(needsPhotoUpload(student)).toBe(true);
    });

    it('is false once a photo has been uploaded', () => {
        const student = makeStudent({ photoPath: 'students/1/avatar.jpg', photoUrl: 'https://example.test/avatar.jpg' });
        expect(needsPhotoUpload(student)).toBe(false);
    });

    it('is true for an empty-string photoPath, not just null', () => {
        // Defensive case: the API contract is `string | null`, but an empty
        // string should never read as "has a photo" if it ever slipped through.
        const student = makeStudent({ photoPath: '' });
        expect(needsPhotoUpload(student)).toBe(true);
    });

    it.each([Role.SystemAdmin, Role.CsgAdmin, Role.ScAdmin, Role.Officer])(
        'is false for a %s with no photo — only students are gated',
        (role) => {
            const student = makeStudent({ role, photoPath: null });
            expect(needsPhotoUpload(student)).toBe(false);
        },
    );

    it('is false when there is no student at all (logged out)', () => {
        expect(needsPhotoUpload(null)).toBe(false);
        expect(needsPhotoUpload(undefined)).toBe(false);
    });
});
