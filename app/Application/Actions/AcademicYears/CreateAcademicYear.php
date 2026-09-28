<?php

namespace App\Application\Actions\AcademicYears;

use App\Models\AcademicYear;
use App\Models\Student;

final class CreateAcademicYear
{
    /** Always created inactive; activation is its own guarded action. */
    public function __invoke(array $data, Student $createdBy): AcademicYear
    {
        return AcademicYear::create([
            'name' => $data['name'],
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'is_active' => false,
            'created_by' => $createdBy->id,
        ]);
    }
}
