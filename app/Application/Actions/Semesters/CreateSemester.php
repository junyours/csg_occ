<?php

namespace App\Application\Actions\Semesters;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class CreateSemester
{
    /**
     * @param  array{name: string, start_date?: string|null, end_date?: string|null, is_active?: bool}  $data
     */
    public function __invoke(AcademicYear $academicYear, array $data, Student $createdBy): Semester
    {
        return DB::transaction(function () use ($academicYear, $data, $createdBy) {
            if (! empty($data['is_active'])) {
                // Only one semester is active per academic year at a
                // time — creating this one as active retires whichever
                // one currently is, scoped to this academic year only
                // (a different academic year's active semester is
                // untouched).
                Semester::forAcademicYear($academicYear->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            return Semester::create([
                'academic_year_id' => $academicYear->id,
                'name' => $data['name'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => $data['is_active'] ?? false,
                'created_by' => $createdBy->id,
            ]);
        });
    }
}
