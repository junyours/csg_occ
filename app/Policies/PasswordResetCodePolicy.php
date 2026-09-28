<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\Student;

class PasswordResetCodePolicy
{
    /**
     * Who may open the Password Reset Codes screen: the three admin tiers.
     * Which codes each of them actually sees is narrowed further, by role
     * rank and department, in BuildPasswordResetCodeList — this is only the
     * coarse "can you open the screen at all" gate.
     */
    public function viewAny(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin, Role::ScAdmin], true);
    }
}
