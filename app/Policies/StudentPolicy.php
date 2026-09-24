<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class StudentPolicy
{
    /**
     * Spec §4.1: CSG Admin (and System Admin, which carries every CSG Admin
     * power per §2) can add a student to any department. SC Admin can only
     * add within the department they administer (sc_admin_department_id).
     */
    public function create(Student $user, int $departmentId): bool
    {
        if (in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true)) {
            return true;
        }

        if ($user->role === Role::ScAdmin) {
            return $user->sc_admin_department_id === $departmentId;
        }

        return false;
    }

    /**
     * Spec §4.2: bulk import is available to the same actors as single-add
     * (§4.1) — CSG/System Admin for any department, SC Admin for their
     * own. Only a coarse "can this actor import at all" gate: which
     * departments actually appear in the file isn't known until it's
     * parsed, so the per-row department boundary is enforced in
     * BulkImportStudents via the `create` ability above, one row at a time.
     */
    public function bulkImport(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin, Role::ScAdmin], true);
    }

    /**
     * Spec §10: GET /students is "scoped by role — CSG sees all, SC sees
     * own dept". That's an admin/roster-management endpoint, not a general
     * directory — Officers only need the scan endpoint and a Student only
     * ever sees themselves (via /auth/me), so neither gets list access here.
     */
    public function viewAny(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin, Role::ScAdmin], true);
    }

    /**
     * Spec §4.4: CSG Admin can promote to sc_admin or officer, and — as
     * the natural inverse of that same power — demote back to student.
     * System Admin has every CSG Admin power plus promoting to csg_admin,
     * so it can also demote a csg_admin back to student; a CSG Admin
     * cannot, since csg_admin never appears in its own assignable set.
     *
     * A role is only touchable — as either the target's current role or
     * the requested new role — if it's in the actor's assignable set.
     * That symmetry is what makes demotion "the same power in reverse"
     * rather than a separate one: it also keeps a CSG Admin from reaching
     * into a System Admin's or another CSG Admin's role at all, and keeps
     * anyone from reassigning their own role through this endpoint.
     */
    public function assignRole(Student $user, Student $target, Role $newRole): bool
    {
        if ($user->is($target)) {
            return false;
        }

        $assignable = match ($user->role) {
            Role::SystemAdmin => [Role::CsgAdmin, Role::ScAdmin, Role::Officer, Role::Student],
            Role::CsgAdmin => [Role::ScAdmin, Role::Officer, Role::Student],
            default => [],
        };

        return in_array($newRole, $assignable, true)
            && ($target->role === Role::Student || in_array($target->role, $assignable, true));
    }

    /**
     * Spec §4.5: a student manages their own photo. CSG/System Admin can
     * also set it for any student (useful for producing ID cards before
     * a student has ever logged in), and SC Admin can do the same but
     * only within their own department — the same boundary §4.1 already
     * draws for adding students in the first place.
     */
    public function updatePhoto(Student $user, Student $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        if (in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true)) {
            return true;
        }

        if ($user->role === Role::ScAdmin) {
            return $user->sc_admin_department_id === $target->department_id;
        }

        return false;
    }

    /**
     * Spec §4.5/§5: a student can view/download/print their own QR.
     * Admin access mirrors updatePhoto's rule exactly — both are really
     * "who manages this student's ID artifacts" — since printing ID
     * badges is an admin task that needs the same reach.
     */
    public function viewQr(Student $user, Student $target): bool
    {
        return $this->updatePhoto($user, $target);
    }
}
