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

function semesterTestAcademicYear(Student $createdBy, string $name = '2026-2027', bool $active = false): AcademicYear
{
    return AcademicYear::firstOrCreate(['name' => $name], ['created_by' => $createdBy->id, 'is_active' => $active]);
}

it('rejects an unauthenticated semester list request', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);

    $this->getJson("/api/academic-years/{$academicYear->id}/semesters")->assertStatus(401);
});

it('lets any authenticated role list semesters for an academic year', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    Semester::create(['academic_year_id' => $academicYear->id, 'name' => 'semester_1', 'created_by' => $admin->id]);
    $officer = semesterTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/academic-years/{$academicYear->id}/semesters")
        ->assertStatus(200)
        ->assertJsonCount(1);
});

it('rejects semester creation from a non-admin role', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);
    $officer = semesterTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/academic-years/{$academicYear->id}/semesters", ['name' => 'semester_1'])
        ->assertStatus(403);
});

it('lets a system admin create a semester, always inactive', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $academicYear = semesterTestAcademicYear($admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/academic-years/{$academicYear->id}/semesters", ['name' => 'semester_1', 'start_date' => '2026-08-01', 'end_date' => '2026-12-15', 'is_active' => true])
        ->assertStatus(201)
        ->assertJsonPath('academic_year_id', $academicYear->id)
        ->assertJsonPath('is_active', false);
});

it('rejects semester create/edit/activate/deactivate from a csg admin', function () {
    $sys = semesterTestStaff('system_admin', '2020000009');
    $csg = semesterTestStaff('csg_admin', '2020000001');
    $year = semesterTestAcademicYear($sys, '2026-2027', true);
    $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'created_by' => $sys->id]);

    $this->actingAs($csg, 'sanctum')->postJson("/api/academic-years/{$year->id}/semesters", ['name' => 'semester_2'])->assertStatus(403);
    $this->actingAs($csg, 'sanctum')->patchJson("/api/semesters/{$semester->id}", ['start_date' => '2026-08-15'])->assertStatus(403);
    $this->actingAs($csg, 'sanctum')->postJson("/api/semesters/{$semester->id}/activate")->assertStatus(403);
    $this->actingAs($csg, 'sanctum')->postJson("/api/semesters/{$semester->id}/deactivate")->assertStatus(403);

    expect($semester->fresh()->is_active)->toBeFalse();
});

it('rejects a duplicate semester name within the same academic year', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $year = semesterTestAcademicYear($admin);
    Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/academic-years/{$year->id}/semesters", ['name' => 'semester_1'])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

it('lets a system admin edit a semester without touching is_active', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $year = semesterTestAcademicYear($admin);
    $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'is_active' => true, 'created_by' => $admin->id]);

    $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/semesters/{$semester->id}", ['start_date' => '2026-08-15'])
        ->assertStatus(200)->assertJsonPath('is_active', true);

    expect($response->json('start_date'))->toStartWith('2026-08-15');
});

it('activates a semester of the active year; 409 if another is active or the year is inactive', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $year = semesterTestAcademicYear($admin, '2026-2027', true);
    $other = semesterTestAcademicYear($admin, '2025-2026', false);
    $one = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'created_by' => $admin->id]);
    $two = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_2', 'created_by' => $admin->id]);
    $inactiveYearSem = Semester::create(['academic_year_id' => $other->id, 'name' => 'semester_1', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/semesters/{$inactiveYearSem->id}/activate")->assertStatus(409);
    $this->actingAs($admin, 'sanctum')->postJson("/api/semesters/{$one->id}/activate")->assertStatus(200)->assertJsonPath('is_active', true);
    $this->actingAs($admin, 'sanctum')->postJson("/api/semesters/{$two->id}/activate")
        ->assertStatus(409)->assertJsonPath('message', 'Deactivate the current semester first.');

    expect($one->fresh()->is_active)->toBeTrue()->and($two->fresh()->is_active)->toBeFalse();
});

it('lets a system admin deactivate a semester, sweeping the other accounts', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $csg = semesterTestStaff('csg_admin', '2020000002');
    $year = semesterTestAcademicYear($admin, '2026-2027', true);
    $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'is_active' => true, 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/semesters/{$semester->id}/deactivate")
        ->assertStatus(200)->assertJsonPath('is_active', false);

    expect($csg->fresh()->role->value)->toBe('student')
        ->and($csg->fresh()->status)->toBe('inactive')
        ->and($admin->fresh()->status)->toBe('active');
});

it('answers 409 naming ongoing events when deactivating', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $year = semesterTestAcademicYear($admin, '2026-2027', true);
    $semester = Semester::create(['academic_year_id' => $year->id, 'name' => 'semester_1', 'is_active' => true, 'created_by' => $admin->id]);
    \App\Models\EventModel::create(['name' => 'Foundation Day', 'created_by' => $admin->id, 'semester_id' => $semester->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/semesters/{$semester->id}/deactivate")
        ->assertStatus(409)->assertJsonPath('events', ['Foundation Day']);

    expect($semester->fresh()->is_active)->toBeTrue();
});

it('rejects semester activate/deactivate from a non-admin role', function () {
    $admin = semesterTestStaff('system_admin', '2020000001');
    $officer = semesterTestStaff('officer', '2020200001');
    $semester = Semester::create(['academic_year_id' => semesterTestAcademicYear($admin)->id, 'name' => 'semester_1', 'created_by' => $admin->id]);

    $this->actingAs($officer, 'sanctum')->postJson("/api/semesters/{$semester->id}/activate")->assertStatus(403);
    $this->actingAs($officer, 'sanctum')->postJson("/api/semesters/{$semester->id}/deactivate")->assertStatus(403);
});
