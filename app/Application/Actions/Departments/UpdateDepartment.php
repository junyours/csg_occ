<?php

namespace App\Application\Actions\Departments;

use App\Models\Department;

final class UpdateDepartment
{
    /**
     * @param  array{name?: string, code?: string}  $data
     */
    public function __invoke(Department $department, array $data): Department
    {
        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper($data['code']);
        }

        $department->update($data);

        return $department->fresh();
    }
}
