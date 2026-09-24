<?php

use App\Application\Actions\AcademicYears\CreateAcademicYear;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAcademicYearTestAdmin(): Student
{
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);

    return Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
}

it('creates an academic year owned by the given creator', function () {
    $admin = createAcademicYearTestAdmin();

    $academicYear = (new CreateAcademicYear)([
        'name' => '2026-2027',
        'start_date' => '2026-08-01',
        'end_date' => '2027-05-31',
    ], $admin);

    expect($academicYear->name)->toBe('2026-2027')
        ->and($academicYear->is_active)->toBeFalse()
        ->and($academicYear->created_by)->toBe($admin->id);
});

it('retires the previously active academic year when creating a new active one', function () {
    $admin = createAcademicYearTestAdmin();
    $current = (new CreateAcademicYear)(['name' => '2025-2026', 'is_active' => true], $admin);

    $next = (new CreateAcademicYear)(['name' => '2026-2027', 'is_active' => true], $admin);

    expect($current->fresh()->is_active)->toBeFalse()
        ->and($next->is_active)->toBeTrue()
        ->and(AcademicYear::where('is_active', true)->count())->toBe(1);
});
