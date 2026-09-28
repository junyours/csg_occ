<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\NoActiveSemesterException;
use App\Domain\Exceptions\SystemAdminAccountException;
use App\Domain\Support\CurrentSemester;
use App\Models\Student;

final class ReenrollStudent
{
    /**
     * "Update" half of the import / single-add upsert: current semester, this
     * term's placement, active again. Never touches number, name, credentials,
     * QR or role (history is protected by the record snapshot).
     */
    public function __invoke(Student $student, array $data): Student
    {
        if ($student->role === Role::SystemAdmin) {
            throw new SystemAdminAccountException;
        }

        $semesterId = CurrentSemester::id()
            ?? throw new NoActiveSemesterException('There is no active semester to enroll students into.');

        $student->update([
            'semester_id' => $semesterId,
            'department_id' => $data['department_id'],
            'major' => $data['major'] ?? null,
            'year_level' => $data['year_level'],
            'section' => $data['section'] ?? null,
            'date_enrolled' => $data['date_enrolled'] ?? $student->date_enrolled,
            'status' => Student::STATUS_ACTIVE,
        ]);

        return $student->fresh();
    }
}
