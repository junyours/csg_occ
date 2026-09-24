<?php

use App\Application\Actions\Semesters\ActivateSemester;
use App\Application\Actions\Semesters\DeactivateSemester;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function semesterActivationTestAdmin(): Student
{
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);

    return Student::create([
        'student_number' => '2020000002',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin2', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
}

it('activates a semester and deactivates every other one in the same academic year', function () {
    $admin = semesterActivationTestAdmin();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);
    $old = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $new = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_2',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $activated = (new ActivateSemester)($new);

    expect($activated->is_active)->toBeTrue()
        ->and($old->fresh()->is_active)->toBeFalse()
        ->and(Semester::forAcademicYear($academicYear->id)->where('is_active', true)->count())->toBe(1);
});

it('leaves an active semester in a different academic year untouched', function () {
    $admin = semesterActivationTestAdmin();
    $yearOne = AcademicYear::create(['name' => '2025-2026', 'created_by' => $admin->id]);
    $yearTwo = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);
    $otherYearActive = Semester::create([
        'academic_year_id' => $yearOne->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $target = Semester::create([
        'academic_year_id' => $yearTwo->id, 'name' => 'semester_1',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    (new ActivateSemester)($target);

    expect($otherYearActive->fresh()->is_active)->toBeTrue();
});

it('deactivates a semester, allowing zero active semesters in that academic year', function () {
    $admin = semesterActivationTestAdmin();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);
    $semester = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);

    $deactivated = (new DeactivateSemester)($semester);

    expect($deactivated->is_active)->toBeFalse()
        ->and(Semester::forAcademicYear($academicYear->id)->where('is_active', true)->count())->toBe(0);
});
