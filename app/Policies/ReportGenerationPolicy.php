<?php

namespace App\Policies;

use App\Domain\Enums\Role;
use App\Models\ReportGeneration;
use App\Models\Student;

class ReportGenerationPolicy
{
    /**
     * Whoever kicked off the generation can always poll/download it. A
     * System or CSG Admin can also reach any generation (support/
     * oversight), matching the broader access those two roles already
     * have over every other roster-report endpoint. An SC Admin only
     * sees their own requests — their department scoping is already
     * baked into the report at creation time (RosterReportGenerationController),
     * so there's nothing extra for them to see on someone else's row.
     */
    public function view(Student $user, ReportGeneration $reportGeneration): bool
    {
        return $user->id === $reportGeneration->requested_by
            || in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin], true);
    }
}
