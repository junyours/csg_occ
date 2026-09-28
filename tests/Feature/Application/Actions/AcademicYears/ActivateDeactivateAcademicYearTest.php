<?php

use App\Application\Actions\AcademicYears\ActivateAcademicYear;
use App\Application\Actions\AcademicYears\DeactivateAcademicYear;
use App\Models\AcademicYear;
use App\Domain\Exceptions\SemesterStillActiveException;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function activationTestAdmin(): Student
{
    $department = Department::create(['name' => 'CCS', 'code' => 'CCS']);

    return Student::create([
        'student_number' => '2020000002',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'csgadmin2', 'password' => 'password',
        'role' => 'system_admin',
    ]);
}

it('activates an academic year and deactivates every other one', function () {
    $admin = activationTestAdmin();
    $old = AcademicYear::create(['name' => '2025-2026', 'is_active' => true, 'created_by' => $admin->id]);
    $new = AcademicYear::create(['name' => '2026-2027', 'is_active' => false, 'created_by' => $admin->id]);

    $activated = (new ActivateAcademicYear)($new);

    expect($activated->is_active)->toBeTrue()
        ->and($old->fresh()->is_active)->toBeFalse()
        ->and(AcademicYear::where('is_active', true)->count())->toBe(1);
});

it('deactivates an academic year, allowing zero active years', function () {
    $admin = activationTestAdmin();
    $year = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);

    $deactivated = (new DeactivateAcademicYear)($year);

    expect($deactivated->is_active)->toBeFalse()
        ->and(AcademicYear::where('is_active', true)->count())->toBe(0);
});

it('refuses to activate or deactivate an academic year while any semester is active', function () {
    $admin = activationTestAdmin();
    $running = AcademicYear::create(['name' => '2025-2026', 'is_active' => true, 'created_by' => $admin->id]);
    Semester::create(['academic_year_id' => $running->id, 'name' => 'semester_1', 'is_active' => true, 'created_by' => $admin->id]);
    $next = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);

    expect(fn () => (new ActivateAcademicYear)($next))->toThrow(SemesterStillActiveException::class)
        ->and(fn () => (new DeactivateAcademicYear)($running))->toThrow(SemesterStillActiveException::class);
});
