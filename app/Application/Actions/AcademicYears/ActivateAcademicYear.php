<?php

namespace App\Application\Actions\AcademicYears;

use App\Models\AcademicYear;
use Illuminate\Support\Facades\DB;

final class ActivateAcademicYear
{
    /**
     * Exactly one academic year is active at a time — it's what the
     * dashboard, new-event forms, etc. default to — so activating one
     * retires every other one in the same transaction.
     */
    public function __invoke(AcademicYear $academicYear): AcademicYear
    {
        return DB::transaction(function () use ($academicYear) {
            AcademicYear::where('id', '!=', $academicYear->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $academicYear->update(['is_active' => true]);

            return $academicYear->fresh();
        });
    }
}
