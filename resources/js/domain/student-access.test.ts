import { describe, expect, it } from 'vitest';

import { canResetStudentPassword } from '@/domain/student-access';
import { Role } from '@/domain/enums';

const person = (id: number, role: Role, departmentId = 1, scAdminDepartmentId: number | null = null) => ({
    id,
    role,
    departmentId,
    scAdminDepartmentId,
});

describe('canResetStudentPassword', () => {
    it('never allows resetting your own account', () => {
        const system = person(1, Role.SystemAdmin);
        expect(canResetStudentPassword(system, system)).toBe(false);
    });

    it('lets a system admin reset anyone else', () => {
        const system = person(1, Role.SystemAdmin);
        for (const role of Object.values(Role)) {
            expect(canResetStudentPassword(system, person(2, role))).toBe(true);
        }
    });

    it('lets a csg admin reset sc admins, officers and students, but not csg or system admins', () => {
        const csg = person(1, Role.CsgAdmin);
        expect(canResetStudentPassword(csg, person(2, Role.ScAdmin, 9))).toBe(true);
        expect(canResetStudentPassword(csg, person(2, Role.Officer, 9))).toBe(true);
        expect(canResetStudentPassword(csg, person(2, Role.Student, 9))).toBe(true);
        expect(canResetStudentPassword(csg, person(2, Role.CsgAdmin))).toBe(false);
        expect(canResetStudentPassword(csg, person(2, Role.SystemAdmin))).toBe(false);
    });

    it('lets an sc admin reset only students and officers of their own department', () => {
        const sc = person(1, Role.ScAdmin, 1, 5);
        expect(canResetStudentPassword(sc, person(2, Role.Student, 5))).toBe(true);
        expect(canResetStudentPassword(sc, person(2, Role.Officer, 5))).toBe(true);
        expect(canResetStudentPassword(sc, person(2, Role.Student, 6))).toBe(false);
        expect(canResetStudentPassword(sc, person(2, Role.ScAdmin, 5))).toBe(false);
        expect(canResetStudentPassword(sc, person(2, Role.CsgAdmin, 5))).toBe(false);
    });

    it('lets an sc admin with no administered department reset nobody', () => {
        const sc = person(1, Role.ScAdmin, 1, null);
        expect(canResetStudentPassword(sc, person(2, Role.Student, 1))).toBe(false);
    });

    it('never lets officers or students reset anyone', () => {
        expect(canResetStudentPassword(person(1, Role.Officer), person(2, Role.Student))).toBe(false);
        expect(canResetStudentPassword(person(1, Role.Student), person(2, Role.Student))).toBe(false);
    });
});
