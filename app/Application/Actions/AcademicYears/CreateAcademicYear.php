<?php

namespace App\Application\Actions\AcademicYears;

use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class CreateAcademicYear
{
    /**
     * @param  array{name: string, start_date?: string|null, end_date?: string|null, is_active?: bool}  $data
     */
    public function __invoke(array $data, Student $createdBy): AcademicYear
    {
        return DB::transaction(function () use ($data, $createdBy) {
            if (! empty($data['is_active'])) {
                // Only one academic year is active at a time — creating this
                // one as active retires whichever one currently is.
                AcademicYear::where('is_active', true)->update(['is_active' => false]);
            }

            return AcademicYear::create([
                'name' => $data['name'],
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => $data['is_active'] ?? false,
                'created_by' => $createdBy->id,
            ]);
        });
    }
}
