<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Exclusion;
use App\Models\Student;

class ExclusionPolicy
{
    /**
     * Exclusions are a CSG-level power (spec §2): System Admin carries every
     * CSG Admin power; SC Admin, Officer, and Student do not manage exclusions.
     */
    public function create(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }

    /**
     * Manage Exclusions screen (student-exclusion-feature-plan.md §4B) —
     * same CSG-only audience as adding/removing one.
     */
    public function viewAny(Student $user): bool
    {
        return $this->create($user);
    }

    /**
     * Removing an exclusion (§6) is the same CSG-level power as creating
     * one — the "has the target scope already ended" restriction is a
     * business rule enforced by RemoveExclusion itself, not a permissions
     * question, so it isn't checked here.
     */
    public function remove(Student $user, Exclusion $exclusion): bool
    {
        return $this->create($user);
    }
}
