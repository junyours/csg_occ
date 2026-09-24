<?php

namespace App\Application\Actions\Semesters;

use App\Models\Semester;
use Illuminate\Support\Facades\DB;

final class ActivateSemester
{
    /**
     * Exactly one semester is active per academic year at a time —
     * mirrors ActivateAcademicYear, but scoped to this semester's own
     * academic_year_id rather than globally, since two different academic
     * years are independent scopes and shouldn't fight over "the" active
     * semester.
     */
    public function __invoke(Semester $semester): Semester
    {
        return DB::transaction(function () use ($semester) {
            Semester::where('academic_year_id', $semester->academic_year_id)
                ->where('id', '!=', $semester->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $semester->update(['is_active' => true]);

            return $semester->fresh();
        });
    }
}
