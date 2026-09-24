<?php

namespace App\Application\Actions\Events;

use App\Domain\Exceptions\NoActiveSemesterException;
use App\Models\AcademicYear;
use App\Models\EventModel;
use App\Models\Semester;
use App\Models\Student;

final class CreateEvent
{
    /**
     * @param array{name: string, description?: string|null, semester_id?: int|null, department_ids: array<int, int>} $data
     */
    public function __invoke(array $data, Student $createdBy): EventModel
    {
        // The semester is no longer chosen by whoever is creating the
        // event — it's resolved here from the active academic year's
        // active semester, so the form can't drift out of sync with
        // whatever admins have marked active elsewhere. An explicit
        // semester_id (e.g. from tests or other internal callers) still
        // wins if one is passed.
        $semesterId = $data['semester_id'] ?? $this->resolveActiveSemesterId();

        $event = EventModel::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $createdBy->id,
            'semester_id' => $semesterId,
        ]);

        // Written as the explicit set the form submitted (every department
        // checkbox starts checked, so "no restriction" and "every
        // department picked" land here identically) — see
        // EventModel::includedDepartmentIds for what an empty set means.
        $event->departments()->sync($data['department_ids']);

        // Refresh: `status` defaults to `ongoing` at the schema level and is
        // deliberately never set here (same reasoning as CreateSession) —
        // the DB row has it right after insert, but Eloquent doesn't
        // hydrate DB-side column defaults back onto the in-memory model.
        return $event->refresh()->load('departments');
    }

    private function resolveActiveSemesterId(): int
    {
        $activeAcademicYear = AcademicYear::active()->first();

        $activeSemester = $activeAcademicYear
            ? Semester::active()->forAcademicYear($activeAcademicYear->id)->first()
            : null;

        if (! $activeSemester) {
            throw new NoActiveSemesterException;
        }

        return $activeSemester->id;
    }
}
