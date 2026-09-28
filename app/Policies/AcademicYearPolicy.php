<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class AcademicYearPolicy
{
    /**
     * Every authenticated account needs the academic year list — it's how
     * every dashboard (present/missed/late/penalties/events/students)
     * scopes itself, and how a student's own history is grouped. Viewing
     * isn't restricted, only management is.
     */
    public function viewAny(Student $user): bool
    {
        return true;
    }

    /** Managing academic years (incl. activate/deactivate) is System Admin only. */
    public function create(Student $user): bool
    {
        return $user->role === Role::SystemAdmin;
    }

    public function update(Student $user): bool
    {
        return $this->create($user);
    }
}
