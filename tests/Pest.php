<?php

use App\Domain\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Students can't be created/imported without an active semester in the
 * active academic year. The creator is a System Admin, so it never counts
 * as an attendee in any roster.
 */
function seedActiveSemester(string $semesterName = 'semester_1'): Semester
{
    $department = Department::firstOrCreate(['code' => 'SEEDSYS'], ['name' => 'Seed System']);

    $creator = Student::firstOrCreate(
        ['student_number' => 'SEED-SYSADMIN'],
        [
            'last_name' => 'Admin', 'first_name' => 'System',
            'department_id' => $department->id,
            'username' => 'seed-sysadmin', 'password' => 'password',
            'role' => Role::SystemAdmin,
        ],
    );

    $year = AcademicYear::firstOrCreate(['name' => '2026-2027'], ['is_active' => true, 'created_by' => $creator->id]);
    $year->update(['is_active' => true]);

    return Semester::firstOrCreate(
        ['academic_year_id' => $year->id, 'name' => $semesterName],
        ['is_active' => true, 'created_by' => $creator->id],
    );
}
