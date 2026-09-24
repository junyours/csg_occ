<?php

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "semesterTest*" to avoid colliding with same-purpose helpers
// declared in other test files — Pest loads every test file's top-level
// functions into one process.
function semesterTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function semesterTestStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => semesterTestDept()->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function semesterTestAcademicYear(Student $createdBy, string $name = '2026-2027'): AcademicYear
{
    return AcademicYear::firstOrCreate(['name' => $name], ['created_by' => $createdBy->id]);
}

it('rejects an unauthenticated semester list request', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);

    $this->getJson("/api/academic-years/{$academicYear->id}/semesters")->assertStatus(401);
});

it('lets any authenticated role list semesters for an academic year', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    Semester::create(['academic_year_id' => $academicYear->id, 'name' => 'semester_1', 'created_by' => $admin->id]);
    $officer = semesterTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/academic-years/{$academicYear->id}/semesters")
        ->assertStatus(200)
        ->assertJsonCount(1);
});

it('rejects semester creation from a non-admin role', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    $officer = semesterTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/academic-years/{$academicYear->id}/semesters", ['name' => 'semester_1'])
        ->assertStatus(403);
});

it('lets a csg admin create a semester under an academic year', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$academicYear->id}/semesters", [
            'name' => 'semester_1',
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-15',
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'semester_1')
        ->assertJsonPath('academic_year_id', $academicYear->id)
        ->assertJsonPath('is_active', false);
});

it('rejects a duplicate semester name within the same academic year', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    Semester::create(['academic_year_id' => $academicYear->id, 'name' => 'semester_1', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$academicYear->id}/semesters", ['name' => 'semester_1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('allows the same semester name across two different academic years', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $yearOne = semesterTestAcademicYear($admin, '2025-2026');
    $yearTwo = semesterTestAcademicYear($admin, '2026-2027');
    Semester::create(['academic_year_id' => $yearOne->id, 'name' => 'semester_1', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$yearTwo->id}/semesters", ['name' => 'semester_1'])
        ->assertStatus(201);
});

it('lets a csg admin edit a semester without touching is_active', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    $semester = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/semesters/{$semester->id}", ['start_date' => '2026-08-15'])
        ->assertStatus(200)
        ->assertJsonPath('is_active', true);

    expect($response->json('start_date'))->toStartWith('2026-08-15');
});

it('lets a csg admin activate a semester, retiring the previous one in the same academic year', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    $old = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $new = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_2',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/semesters/{$new->id}/activate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', true);

    expect($old->fresh()->is_active)->toBeFalse();
});

it('does not retire an active semester belonging to a different academic year', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $yearOne = semesterTestAcademicYear($admin, '2025-2026');
    $yearTwo = semesterTestAcademicYear($admin, '2026-2027');
    $otherYearActive = Semester::create([
        'academic_year_id' => $yearOne->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);
    $target = Semester::create([
        'academic_year_id' => $yearTwo->id, 'name' => 'semester_1',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/semesters/{$target->id}/activate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', true);

    expect($otherYearActive->fresh()->is_active)->toBeTrue();
});

it('lets a csg admin deactivate a semester', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    $semester = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => true, 'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/semesters/{$semester->id}/deactivate")
        ->assertStatus(200)
        ->assertJsonPath('is_active', false);
});

it('rejects semester activate/deactivate from a non-admin role', function () {
    $admin = semesterTestStaff('csg_admin', '2020000001');
    $officer = semesterTestStaff('officer', '2020200001');
    $academicYear = semesterTestAcademicYear($admin);
    $semester = Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1', 'created_by' => $admin->id,
    ]);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/semesters/{$semester->id}/activate")
        ->assertStatus(403);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/semesters/{$semester->id}/deactivate")
        ->assertStatus(403);
});
