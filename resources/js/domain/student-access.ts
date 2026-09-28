import type { Student } from '@/domain/entities';
import { Role } from '@/domain/enums';

/**
 * Whether `actor` may reset `target`'s password to the default. Mirrors
 * App\Policies\StudentPolicy::resetPassword — the server is the one that
 * actually enforces it; this only decides whether to show the button.
 *
 *  - System Admin: everyone else.
 *  - CSG Admin: SC Admins, Officers and Students (any department).
 *  - SC Admin: Officers and Students of the department they administer.
 *  - Nobody resets their own account (that's what Change password is for).
 */
export function canResetStudentPassword(
    actor: Pick<Student, 'id' | 'role' | 'scAdminDepartmentId'>,
    target: Pick<Student, 'id' | 'role' | 'departmentId'>,
): boolean {
    if (actor.id === target.id) return false;

    switch (actor.role) {
        case Role.SystemAdmin:
            return true;
        case Role.CsgAdmin:
            return [Role.ScAdmin, Role.Officer, Role.Student].includes(target.role);
        case Role.ScAdmin:
            return (
                actor.scAdminDepartmentId !== null &&
                actor.scAdminDepartmentId === target.departmentId &&
                [Role.Officer, Role.Student].includes(target.role)
            );
        default:
            return false;
    }
}
