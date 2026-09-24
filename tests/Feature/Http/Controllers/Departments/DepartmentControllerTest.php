<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "departmentTest*" to avoid colliding with same-purpose helpers
// declared in other test files (exclusionDept, exclusionApiDept, etc.) —
// Pest loads every test file's top-level functions into one process.
function departmentTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function departmentTestStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => departmentTestDept()->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects an unauthenticated department list request', function () {
    $this->getJson('/api/departments')->assertStatus(401);
});

it('lets any authenticated role list departments', function () {
    departmentTestDept('BSIT');
    $officer = departmentTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/departments')
        ->assertStatus(200)
        ->assertJsonCount(2); // bootstrap CCS + BSIT
});

it('rejects department creation from a non-admin role', function () {
    $officer = departmentTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/departments', ['name' => 'New Dept', 'code' => 'ND'])
        ->assertStatus(403);
});

it('lets a csg admin create a department', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/departments', ['name' => 'New Department', 'code' => 'nd'])
        ->assertStatus(201)
        ->assertJsonPath('code', 'ND');
});

it('rejects a duplicate department code regardless of casing', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    departmentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/departments', ['name' => 'Duplicate', 'code' => 'bsit'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('validates required fields on department creation', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/departments', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'code']);
});

it('rejects department update from a non-admin role', function () {
    $officer = departmentTestStaff('officer', '2020200001');
    $dept = departmentTestDept('BSIT');

    $this->actingAs($officer, 'sanctum')
        ->patchJson("/api/departments/{$dept->id}", ['name' => 'Renamed'])
        ->assertStatus(403);
});

it('lets a csg admin update a department name and code', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $dept = departmentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/departments/{$dept->id}", ['name' => 'BS Information Technology', 'code' => 'it'])
        ->assertStatus(200)
        ->assertJsonPath('name', 'BS Information Technology')
        ->assertJsonPath('code', 'IT');
});

it('allows a partial update with only one field', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $dept = departmentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/departments/{$dept->id}", ['name' => 'Renamed Only'])
        ->assertStatus(200)
        ->assertJsonPath('name', 'Renamed Only')
        ->assertJsonPath('code', 'BSIT');
});

it('rejects a department update that collides with another department\'s code', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    departmentTestDept('BSIT');
    $bsba = departmentTestDept('BSBA');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/departments/{$bsba->id}", ['code' => 'bsit'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('allows a department update that keeps its own existing code', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $dept = departmentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/departments/{$dept->id}", ['code' => 'bsit', 'name' => 'BSIT Updated'])
        ->assertStatus(200)
        ->assertJsonPath('code', 'BSIT');
});

it('rejects department deletion from a non-admin role', function () {
    $officer = departmentTestStaff('officer', '2020200001');
    $dept = departmentTestDept('BSIT');

    $this->actingAs($officer, 'sanctum')
        ->deleteJson("/api/departments/{$dept->id}")
        ->assertStatus(403);
});

it('lets a csg admin delete a department with no students', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $empty = departmentTestDept('EMPTY');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/departments/{$empty->id}")
        ->assertStatus(204);

    expect(Department::find($empty->id))->toBeNull();
});

it('refuses to delete a department that still has a student', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $bsit = departmentTestDept('BSIT');
    Student::create([
        'student_number' => '2020300001',
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => $bsit->id,
        'username' => 'dctrl2020300001',
        'password' => 'password',
        'role' => 'student',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/departments/{$bsit->id}")
        ->assertStatus(409);

    expect(Department::find($bsit->id))->not->toBeNull();
});

it('refuses to delete a department an sc admin is scoped to', function () {
    $admin = departmentTestStaff('csg_admin', '2020000001');
    $bsit = departmentTestDept('BSIT');
    Student::create([
        'student_number' => '2020000099',
        'last_name' => 'Admin', 'first_name' => 'Sc',
        'department_id' => departmentTestDept('CCS')->id,
        'sc_admin_department_id' => $bsit->id,
        'username' => 'dctrl2020000099',
        'password' => 'password',
        'role' => 'sc_admin',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/departments/{$bsit->id}")
        ->assertStatus(409);
});
