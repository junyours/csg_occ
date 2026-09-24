<?php

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "academicYearTest*" to avoid colliding with same-purpose helpers
// declared in other test files — Pest loads every test file's top-level
// functions into one process.
function academicYearTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function academicYearTestStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => academicYearTestDept()->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects an unauthenticated academic year list request', function () {
    $this->getJson('/api/academic-years')->assertStatus(401);
});

it('lets any authenticated role list academic years', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);
    $officer = academicYearTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/academic-years')
        ->assertStatus(200)
        ->assertJsonCount(1);
});

it('rejects academic year creation from a non-admin role', function () {
    $officer = academicYearTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/academic-years', ['name' => '2026-2027'])
        ->assertStatus(403);
});

it('lets a csg admin create an academic year', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/academic-years', [
            'name' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-05-31',
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', '2026-2027')
        ->assertJsonPath('is_active', false);
});

it('rejects a duplicate academic year name', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/academic-years', ['name' => '2026-2027'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('lets a csg admin edit an academic year without touching is_active', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    $year = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/academic-years/{$year->id}", ['name' => '2026-2027 (Revised)'])
        ->assertStatus(200)
        ->assertJsonPath('name', '2026-2027 (Revised)')
        ->assertJsonPath('is_active', true);
});

it('lets a csg admin activate an academic year, retiring the previous one', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    $old = AcademicYear::create(['name' => '2025-2026', 'is_active' => true, 'created_by' => $admin->id]);
    $new = AcademicYear::create(['name' => '2026-2027', 'is_active' => false, 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$new->id}/activate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', true);

    expect($old->fresh()->is_active)->toBeFalse();
});

it('lets a csg admin deactivate an academic year', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    $year = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$year->id}/deactivate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', false);
});

it('rejects activate/deactivate from a non-admin role', function () {
    $admin = academicYearTestStaff('csg_admin', '2020000001');
    $officer = academicYearTestStaff('officer', '2020200001');
    $year = AcademicYear::create(['name' => '2026-2027', 'created_by' => $admin->id]);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/academic-years/{$year->id}/activate")
        ->assertStatus(403);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/academic-years/{$year->id}/deactivate")
        ->assertStatus(403);
});
