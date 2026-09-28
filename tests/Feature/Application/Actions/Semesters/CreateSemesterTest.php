<?php

use App\Application\Actions\Semesters\CreateSemester;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSemesterTestAdmin(): Student
{
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);

    return Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => 'system_admin',
    ]);
}

it('creates a semester owned by the given creator, scoped to the given academic year', function () {
    $admin = createSemesterTestAdmin();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);

    $semester = (new CreateSemester)($academicYear, [
        'name' => 'semester_1',
        'start_date' => '2026-08-01',
        'end_date' => '2026-12-15',
    ], $admin);

    expect($semester->name)->toBe(\App\Domain\Enums\Semester::First)
        ->and($semester->academic_year_id)->toBe($academicYear->id)
        ->and($semester->is_active)->toBeFalse()
        ->and($semester->created_by)->toBe($admin->id);
});

it('always creates a semester inactive and never retires an active one', function () {
    $admin = createSemesterTestAdmin();
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);
    $current = Semester::create(['academic_year_id' => $academicYear->id, 'name' => 'semester_1', 'is_active' => true, 'created_by' => $admin->id]);

    $next = (new CreateSemester)($academicYear, ['name' => 'semester_2', 'is_active' => true], $admin);

    expect($next->is_active)->toBeFalse()
        ->and($current->fresh()->is_active)->toBeTrue()
        ->and(Semester::where('is_active', true)->count())->toBe(1);
});
