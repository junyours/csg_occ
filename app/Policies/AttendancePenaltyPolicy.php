<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\AttendancePenalty;
use App\Models\Student;

class AttendancePenaltyPolicy
{
    /**
     * The admin penalty ledger (GET /penalties) is the same CSG-level tier
     * as reversing one: System Admin and CSG Admin only. Unlike
     * StudentPolicy::viewAny, SC Admin is deliberately left out here —
     * penalties/exclusions are called out as CSG-level powers (spec §2),
     * not extended to SC Admin the way the student roster is.
     */
    public function viewAny(Student $user): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }

    /**
     * Reversing a penalty is a CSG-level power (spec §2), the same tier
     * as exclusions: System Admin carries every CSG Admin power; SC
     * Admin, Officer, and Student never manage penalties.
     */
    public function reverse(Student $user, AttendancePenalty $penalty): bool
    {
        return in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }
}
