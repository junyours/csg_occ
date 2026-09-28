<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\NoActiveSemesterException;
use App\Domain\Exceptions\SystemAdminAccountException;
use App\Domain\Support\CurrentSemester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class UpdateStudentStatus
{
    /** Manual toggle. Reactivating moves the student into the current semester; deactivating revokes tokens. */
    public function __invoke(Student $student, string $status): Student
    {
        if ($student->role === Role::SystemAdmin) {
            throw new SystemAdminAccountException;
        }

        return DB::transaction(function () use ($student, $status) {
            if ($status === Student::STATUS_ACTIVE) {
                $semesterId = CurrentSemester::id()
                    ?? throw new NoActiveSemesterException('There is no active semester to enroll students into.');

                $student->update(['status' => Student::STATUS_ACTIVE, 'semester_id' => $semesterId]);
            } else {
                $student->update(['status' => Student::STATUS_INACTIVE]);
                $student->tokens()->delete();
            }

            return $student->fresh('department');
        });
    }
}
