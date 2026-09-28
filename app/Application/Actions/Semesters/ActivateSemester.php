<?php

namespace App\Application\Actions\Semesters;

use App\Domain\Exceptions\AcademicYearNotActiveException;
use App\Domain\Exceptions\SemesterAlreadyActiveException;
use App\Models\AcademicYear;
use App\Models\Semester;
use Illuminate\Support\Facades\DB;

final class ActivateSemester
{
    /**
     * At most one semester is active system-wide; activation never retires
     * another (the running one must be deactivated first, which runs the
     * sweep). The semester's academic year must be the active one.
     */
    public function __invoke(Semester $semester): Semester
    {
        return DB::transaction(function () use ($semester) {
            if ($semester->is_active) {
                return $semester->fresh();
            }

            if (Semester::where('id', '!=', $semester->id)->where('is_active', true)->exists()) {
                throw new SemesterAlreadyActiveException;
            }

            if (! AcademicYear::whereKey($semester->academic_year_id)->where('is_active', true)->exists()) {
                throw new AcademicYearNotActiveException;
            }

            $semester->update(['is_active' => true]);

            return $semester->fresh();
        });
    }
}
