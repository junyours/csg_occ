<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class DepartmentPolicy
{
    /**
     * Every authenticated account needs the department list for dropdowns
     * (student creation, event scoping, etc.) — spec §3 doesn't restrict
     * viewing, only creation.
     */
    public function viewAny(Student $user): bool
    {
        return true;
    }

    /**
     * Adding departments is a CSG-level power (spec §3), same tier as
     * exclusions: System Admin carries every CSG Admin power.
     */
    public function create(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }

    /**
     * Renaming/recoding an existing department (course) is the same
     * CSG-level power as creating one in the first place.
     */
    public function update(Student $user): bool
    {
        return $this->create($user);
    }

    /**
     * Uploading/replacing a department's logo is management of the
     * department record itself, so it carries the same authorization as
     * editing its name/code.
     */
    public function updateLogo(Student $user): bool
    {
        return $this->create($user);
    }

    /**
     * Deleting a department is the same CSG-level power — the actual
     * "is it safe to remove" question (any students assigned to it) is
     * enforced by DeleteDepartment, not here.
     */
    public function delete(Student $user): bool
    {
        return $this->create($user);
    }
}
