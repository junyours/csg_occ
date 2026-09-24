<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

/**
 * Mirrors AcademicYearPolicy exactly — same tier of power, since a
 * semester is just as foundational a scope as the academic year it
 * belongs to (spec: "all data needs to point to the academic year").
 */
class SemesterPolicy
{
    /**
     * Every authenticated account needs the semester list — same reason
     * as AcademicYearPolicy::viewAny (dashboards, event forms, a
     * student's own history all group by it).
     */
    public function viewAny(Student $user): bool
    {
        return true;
    }

    /**
     * Creating, editing, activating, and deactivating semesters is a
     * CSG-level power — same tier as academic years, departments, and
     * events.
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
