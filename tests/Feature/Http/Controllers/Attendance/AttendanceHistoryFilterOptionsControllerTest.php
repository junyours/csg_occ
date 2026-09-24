<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function filterOptionsDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function filterOptionsStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => filterOptionsDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
    ]);
}

function filterOptionsStudent(string $studentNumber, array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => filterOptionsDept('CCS')->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
    ], $overrides));
}

it('rejects an unauthenticated filter options request', function () {
    $this->getJson('/api/attendance-history/filter-options')->assertStatus(401);
});

it('lets an officer see filter options too, to autosuggest their own scan-history filters', function () {
    $officer = filterOptionsStaff('officer', '2020200001');

    filterOptionsStudent('2023100010', ['major' => 'Web Development', 'year_level' => '1', 'section' => 'A']);

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/attendance-history/filter-options')
        ->assertStatus(200)
        ->assertJson([
            'majors' => ['Web Development'],
            'year_levels' => ['1'],
            'sections' => ['A'],
        ]);
});

it('rejects a plain student viewing filter options', function () {
    $student = filterOptionsStudent('2023100011');

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/attendance-history/filter-options')
        ->assertStatus(403);
});

it('lets a csg admin see distinct major, year level, and section values across departments', function () {
    $admin = filterOptionsStaff('csg_admin', '2020000001');
    $bsba = filterOptionsDept('BSBA');

    filterOptionsStudent('2023100001', ['major' => 'Web Development', 'year_level' => '1', 'section' => 'A']);
    filterOptionsStudent('2023100002', ['major' => 'Web Development', 'year_level' => '2', 'section' => 'B']);
    filterOptionsStudent('2023100003', ['department_id' => $bsba->id, 'major' => null, 'year_level' => '1', 'section' => 'A']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history/filter-options')
        ->assertStatus(200)
        ->assertJson([
            'majors' => ['Web Development'],
            'year_levels' => ['1', '2'],
            'sections' => ['A', 'B'],
        ]);
});

it('scopes an sc admin to their own department', function () {
    $ccs = filterOptionsDept('CCS');
    $bsba = filterOptionsDept('BSBA');
    $scAdmin = filterOptionsStaff('sc_admin', '2020000003', $ccs->id);

    filterOptionsStudent('2023100004', ['department_id' => $ccs->id, 'major' => 'Web Development', 'year_level' => '1', 'section' => 'A']);
    filterOptionsStudent('2023100005', ['department_id' => $bsba->id, 'major' => 'Marketing', 'year_level' => '3', 'section' => 'C']);

    $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/attendance-history/filter-options')
        ->assertStatus(200)
        ->assertJson([
            'majors' => ['Web Development'],
            'year_levels' => ['1'],
            'sections' => ['A'],
        ]);
});

it('omits null and blank values', function () {
    $admin = filterOptionsStaff('csg_admin', '2020000001');

    filterOptionsStudent('2023100006', ['major' => null, 'year_level' => '1', 'section' => '']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history/filter-options')
        ->assertStatus(200)
        ->assertJson([
            'majors' => [],
            'year_levels' => ['1'],
            'sections' => [],
        ]);
});
