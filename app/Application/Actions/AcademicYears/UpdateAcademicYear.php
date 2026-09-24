<?php

namespace App\Application\Actions\AcademicYears;

use App\Models\AcademicYear;

final class UpdateAcademicYear
{
    /**
     * Activation status is deliberately never touched here — "create,
     * activate, deactivate, edit" (spec) treats activation as its own
     * action with its own audit trail via ActivateAcademicYear /
     * DeactivateAcademicYear, not a field an edit form can silently flip.
     *
     * @param  array{name?: string, start_date?: string|null, end_date?: string|null}  $data
     */
    public function __invoke(AcademicYear $academicYear, array $data): AcademicYear
    {
        $academicYear->update([
            'name' => $data['name'] ?? $academicYear->name,
            'start_date' => array_key_exists('start_date', $data) ? $data['start_date'] : $academicYear->start_date,
            'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $academicYear->end_date,
        ]);

        return $academicYear->fresh();
    }
}
