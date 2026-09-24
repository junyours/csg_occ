<?php

namespace App\Application\Actions\Semesters;

use App\Models\Semester;

final class UpdateSemester
{
    /**
     * Activation status is deliberately never touched here — same
     * reasoning as UpdateAcademicYear: activate/deactivate are their own
     * audited actions, not a field an edit form can silently flip. The
     * term itself (name) and academic_year_id are also left alone; a
     * semester's identity shouldn't move between years or terms after
     * creation, only its dates.
     *
     * @param  array{start_date?: string|null, end_date?: string|null}  $data
     */
    public function __invoke(Semester $semester, array $data): Semester
    {
        $semester->update([
            'start_date' => array_key_exists('start_date', $data) ? $data['start_date'] : $semester->start_date,
            'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $semester->end_date,
        ]);

        return $semester->fresh();
    }
}
