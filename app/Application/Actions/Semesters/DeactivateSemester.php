<?php

namespace App\Application\Actions\Semesters;

use App\Models\Semester;

final class DeactivateSemester
{
    /**
     * Deliberately allows zero semesters (within this academic year) to
     * be active at once — mirrors DeactivateAcademicYear. Nothing forces
     * a replacement to be chosen atomically.
     */
    public function __invoke(Semester $semester): Semester
    {
        $semester->update(['is_active' => false]);

        return $semester->fresh();
    }
}
