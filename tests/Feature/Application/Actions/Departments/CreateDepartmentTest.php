<?php

use App\Application\Actions\Departments\CreateDepartment;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a department and normalizes the code to uppercase', function () {
    $department = (new CreateDepartment)([
        'name' => 'Bachelor of Science in Information Technology',
        'code' => 'bsit',
    ]);

    expect($department->code)->toBe('BSIT')
        ->and($department->name)->toBe('Bachelor of Science in Information Technology')
        ->and(Department::count())->toBe(1);
});
