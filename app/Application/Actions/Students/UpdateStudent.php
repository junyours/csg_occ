<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\SystemAdminAccountException;
use App\Models\Student;

final class UpdateStudent
{
    /** "Edit student": profile/enrollment fields only; never number, credentials, role, status. */
    public function __invoke(Student $student, array $data): Student
    {
        if ($student->role === Role::SystemAdmin) {
            throw new SystemAdminAccountException;
        }

        $student->update(array_intersect_key($data, array_flip([
            'last_name', 'first_name', 'middle_name', 'suffix',
            'department_id', 'major', 'year_level', 'section', 'date_enrolled',
        ])));

        return $student->fresh('department');
    }
}
