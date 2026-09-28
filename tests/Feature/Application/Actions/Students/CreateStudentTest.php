<?php

use App\Application\Actions\Students\CreateStudent;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('creates a student with the default password, forced change, and a permanent qr token', function () {
    seedActiveSemester();
    $department = Department::create(['name' => 'BS Info Tech', 'code' => 'BSIT']);

    $student = (new CreateStudent)([
        'student_number' => '2023105413',
        'last_name' => 'Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Dela',
        'department_id' => $department->id,
        'year_level' => '3',
    ]);

    expect($student->username)->toBe('2023105413')
        ->and(Hash::check('password123', $student->password))->toBeTrue()
        ->and($student->must_change_password)->toBeTrue()
        ->and($student->qr_token)->not->toBeNull()
        ->and($student->role->value)->toBe('student')
        ->and($student->status)->toBe('active');
});

it('refuses to create a student when there is no active semester', function () {
    $department = Department::create(['name' => 'BS Info Tech', 'code' => 'BSIT']);

    (new CreateStudent)([
        'student_number' => '2023105413', 'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id, 'year_level' => '3',
    ]);
})->throws(\App\Domain\Exceptions\NoActiveSemesterException::class);
