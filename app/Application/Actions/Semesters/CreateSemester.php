<?php

namespace App\Application\Actions\Semesters;

use App\Models\AcademicYear;
use App\Models\Semester;
use App\Models\Student;

final class CreateSemester
{
    /** Always created inactive; activation is its own guarded action. */
    public function __invoke(AcademicYear $academicYear, array $data, Student $createdBy): Semester
    {
        return Semester::create([
            'academic_year_id' => $academicYear->id,
            'name' => $data['name'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'is_active' => false,
            'created_by' => $createdBy->id,
        ]);
    }
}
