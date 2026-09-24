<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class AcademicYearPolicy
{
    /**
     * Every authenticated account needs the academic year list — it's how
     * every dashboard (present/absent/late/penalties/events/students)
     * scopes itself, and how a student's own history is grouped. Viewing
     * isn't restricted, only management is.
     */
    public function viewAny(Student $user): bool
    {
        return true;
    }

    /**
     * Creating, editing, activating, and deactivating academic years is a
     * CSG-level power — same tier as departments and events (spec §2/§3/§6).
     */
    public function create(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }

    public function update(Student $user): bool
    {
        return $this->create($user);
    }
}
