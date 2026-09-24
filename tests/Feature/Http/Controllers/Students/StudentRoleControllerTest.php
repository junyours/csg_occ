<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function roleCtrlDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function roleCtrlStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => roleCtrlDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'rc'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects unauthenticated role assignment', function () {
    $student = roleCtrlStaff('student', '2020300001');

    $this->patchJson("/api/students/{$student->id}/role", ['role' => 'officer'])
        ->assertStatus(401);
});

it('rejects role assignment from an officer', function () {
    $officer = roleCtrlStaff('officer', '2020200001');
    $target = roleCtrlStaff('student', '2020300001');

    $this->actingAs($officer, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'officer'])
        ->assertStatus(403);
});

it('lets a csg admin promote a student to sc_admin with a department', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');
    $target = roleCtrlStaff('student', '2020300001');
    $dept = roleCtrlDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", [
            'role' => 'sc_admin',
            'department_id' => $dept->id,
        ])
        ->assertStatus(200)
        ->assertJsonPath('role', 'sc_admin')
        ->assertJsonPath('sc_admin_department_id', $dept->id);
});

it('rejects promoting to sc_admin without a department', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');
    $target = roleCtrlStaff('student', '2020300001');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'sc_admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department_id');
});

it('rejects a csg admin promoting a student to csg_admin', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');
    $target = roleCtrlStaff('student', '2020300001');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'csg_admin'])
        ->assertStatus(403);
});

it('lets a system admin promote a student to csg_admin', function () {
    $sysAdmin = roleCtrlStaff('system_admin', '2020000000');
    $target = roleCtrlStaff('student', '2020300001');

    $this->actingAs($sysAdmin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'csg_admin'])
        ->assertStatus(200)
        ->assertJsonPath('role', 'csg_admin');
});

it('lets a csg admin demote an sc_admin back to student', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');
    $dept = roleCtrlDept('BSIT');
    $target = roleCtrlStaff('sc_admin', '2020300001', $dept->id);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'student'])
        ->assertStatus(200)
        ->assertJsonPath('role', 'student')
        ->assertJsonPath('sc_admin_department_id', null);
});

it('rejects a csg admin demoting another csg admin', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');
    $target = roleCtrlStaff('csg_admin', '2020000002');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'student'])
        ->assertStatus(403);
});

it('lets a system admin demote a csg admin back to student', function () {
    $sysAdmin = roleCtrlStaff('system_admin', '2020000000');
    $target = roleCtrlStaff('csg_admin', '2020000002');

    $this->actingAs($sysAdmin, 'sanctum')
        ->patchJson("/api/students/{$target->id}/role", ['role' => 'student'])
        ->assertStatus(200)
        ->assertJsonPath('role', 'student');
});

it('rejects a user reassigning their own role', function () {
    $admin = roleCtrlStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$admin->id}/role", ['role' => 'student'])
        ->assertStatus(403);
});
