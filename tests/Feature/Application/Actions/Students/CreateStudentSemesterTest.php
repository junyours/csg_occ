<?php

use App\Application\Actions\Students\CreateStudent;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function studentSemesterTestSetup(): array
{
    $department = Department::create(['name' => 'BS Info Tech', 'code' => 'BSIT']);
    $admin = Student::create([
        'student_number' => 'TEST-ADMIN',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);

    return [$department, $admin];
}

it('leaves semester_id null when no academic year or semester has been set up yet', function () {
    [$department] = studentSemesterTestSetup();

    $student = (new CreateStudent)([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'year_level' => '3',
    ]);

    expect($student->semester_id)->toBeNull();
});

it('defaults a new student onto the active semester of the active academic year', function () {
    [$department, $admin] = studentSemesterTestSetup();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);
    $activeSemester = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_2',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $student = (new CreateStudent)([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'year_level' => '3',
    ]);

    expect($student->semester_id)->toBe($activeSemester->id)
        ->and($student->semester->academicYear->id)->toBe($academicYear->id);
});

it('ignores an active semester belonging to a non-active academic year', function () {
    [$department, $admin] = studentSemesterTestSetup();
    $inactiveYear = AcademicYear::create(['name' => '2025-2026', 'is_active' => false, 'created_by' => $admin->id]);
    Semester::create([
        'academic_year_id' => $inactiveYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);

    $student = (new CreateStudent)([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'year_level' => '3',
    ]);

    expect($student->semester_id)->toBeNull();
});

it('respects an explicitly given semester_id over the active default', function () {
    [$department, $admin] = studentSemesterTestSetup();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);
    Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $chosen = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'summer',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $student = (new CreateStudent)([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'year_level' => '3',
        'semester_id' => $chosen->id,
    ]);

    expect($student->semester_id)->toBe($chosen->id);
});
