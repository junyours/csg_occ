<?php

use App\Application\Actions\Departments\UpdateDepartment;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('updates name and normalizes an updated code to uppercase', function () {
    $department = Department::create(['name' => 'Old Name', 'code' => 'OLD']);

    $updated = (new UpdateDepartment)($department, ['name' => 'New Name', 'code' => 'new']);

    expect($updated->name)->toBe('New Name')
        ->and($updated->code)->toBe('NEW');
});

it('leaves fields untouched when omitted from the update', function () {
    $department = Department::create(['name' => 'Original', 'code' => 'ORIG']);

    $updated = (new UpdateDepartment)($department, ['name' => 'Renamed']);

    expect($updated->name)->toBe('Renamed')
        ->and($updated->code)->toBe('ORIG');
});
