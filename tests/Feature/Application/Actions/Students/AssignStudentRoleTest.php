<?php

use App\Application\Actions\Students\AssignStudentRole;
use App\Domain\Enums\Role;
use App\Models\Department;
use App\Models\RoleAssignment;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function assignRoleTestDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function assignRoleTestStudent(string $studentNumber, string $role = 'student'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => assignRoleTestDept('CCS')->id,
        'username' => 'u'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('promotes a student to sc_admin and sets their department scope', function () {
    $student = assignRoleTestStudent('2020300001');
    $admin = assignRoleTestStudent('2020000001', 'csg_admin');
    $dept = assignRoleTestDept('BSIT');

    $updated = (new AssignStudentRole)($student, Role::ScAdmin, $dept->id, null, $admin);

    expect($updated->role)->toBe(Role::ScAdmin)
        ->and($updated->sc_admin_department_id)->toBe($dept->id)
        ->and($updated->officer_event_id)->toBeNull();

    $log = RoleAssignment::first();
    expect(RoleAssignment::query()->count())->toBe(1)
        ->and($log->old_role)->toBe(Role::Student)
        ->and($log->new_role)->toBe(Role::ScAdmin)
        ->and($log->changed_by)->toBe($admin->id);
});

it('promotes a student to officer without requiring an event scope', function () {
    $student = assignRoleTestStudent('2020300002');
    $admin = assignRoleTestStudent('2020000002', 'csg_admin');

    $updated = (new AssignStudentRole)($student, Role::Officer, null, null, $admin);

    expect($updated->role)->toBe(Role::Officer)
        ->and($updated->officer_event_id)->toBeNull();
});

it('clears the old scope field when the new role has a different kind of scope', function () {
    $student = assignRoleTestStudent('2020300003', 'sc_admin');
    $student->update(['sc_admin_department_id' => assignRoleTestDept('BSBA')->id]);
    $admin = assignRoleTestStudent('2020000003', 'csg_admin');

    $updated = (new AssignStudentRole)($student, Role::Officer, null, null, $admin);

    expect($updated->role)->toBe(Role::Officer)
        ->and($updated->sc_admin_department_id)->toBeNull();
});

it('demotes a student back to student and clears every scope field', function () {
    $dept = assignRoleTestDept('EDUC');
    $student = assignRoleTestStudent('2020300004', 'sc_admin');
    $student->update(['sc_admin_department_id' => $dept->id]);
    $admin = assignRoleTestStudent('2020000004', 'csg_admin');

    $updated = (new AssignStudentRole)($student, Role::Student, null, null, $admin);

    expect($updated->role)->toBe(Role::Student)
        ->and($updated->sc_admin_department_id)->toBeNull()
        ->and($updated->officer_event_id)->toBeNull();

    $log = RoleAssignment::latest('id')->first();
    expect($log->old_role)->toBe(Role::ScAdmin)
        ->and($log->new_role)->toBe(Role::Student);
});
