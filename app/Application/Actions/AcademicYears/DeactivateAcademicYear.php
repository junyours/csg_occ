<?php

namespace App\Application\Actions\AcademicYears;

use App\Models\AcademicYear;

final class DeactivateAcademicYear
{
    /**
     * Deliberately allows zero academic years to be active at once (e.g.
     * end of year, before the next one is created/activated) — nothing
     * forces a replacement to be chosen atomically. Dashboards and
     * new-event forms fall back to "no default, pick one" when that's
     * the case, rather than this action guessing a replacement.
     */
    public function __invoke(AcademicYear $academicYear): AcademicYear
    {
        $academicYear->update(['is_active' => false]);

        return $academicYear->fresh();
    }
}
