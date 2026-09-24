<?php

namespace App\Application\Actions\Departments;

use App\Models\Department;

final class CreateDepartment
{
    /**
     * @param array{name: string, code: string} $data
     */
    public function __invoke(array $data): Department
    {
        return Department::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
        ]);
    }
}
