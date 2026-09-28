<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "studentTest*" — Pest loads every test file's top-level
// functions into one process, so names must stay unique suite-wide.
function studentTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function studentTestStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => studentTestDept()->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Clears the photo.uploaded gate, so a plain-Student fixture
        // reaches the policy check (403) under test instead of being
        // stopped early by the photo gate (423) — that gate is covered
        // separately by EnsurePhotoHasBeenUploadedTest.
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

function studentTestPayload(Department $department, string $studentNumber = '2023105413'): array
{
    return [
        'student_number' => $studentNumber,
        'last_name' => 'Cruz',
        'first_name' => 'Juan',
        'middle_name' => 'Dela',
        'department_id' => $department->id,
        'year_level' => '3',
    ];
}

it('rejects an unauthenticated student creation request', function () {
    $this->postJson('/api/students', [])->assertStatus(401);
});

it('rejects an unauthenticated student list request', function () {
    $this->getJson('/api/students')->assertStatus(401);
});

it('rejects an officer listing students', function () {
    $officer = studentTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/students')
        ->assertStatus(403);
});

it('rejects a student listing students', function () {
    $student = studentTestStaff('student', '2020400001');

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/students')
        ->assertStatus(403);
});

it('lets a csg admin list students across every department', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $bsit = studentTestDept('BSIT');
    $educ = studentTestDept('EDUC');
    Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['username' => 'u1', 'password' => 'password']));
    Student::create(array_merge(studentTestPayload($educ, '2023000002'), ['username' => 'u2', 'password' => 'password']));

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/students')
        ->assertStatus(200);

    // The admin themselves plus the two students just created.
    expect($response->json('total'))->toBe(3);
});

it('scopes an sc admin student list to their own department, ignoring a foreign department_id', function () {
    $bsit = studentTestDept('BSIT');
    $educ = studentTestDept('EDUC');
    $scAdmin = studentTestStaff('sc_admin', '2020300001', $bsit->id);
    Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['username' => 'u1', 'password' => 'password']));
    Student::create(array_merge(studentTestPayload($educ, '2023000002'), ['username' => 'u2', 'password' => 'password']));

    $response = $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/students?department_id='.$educ->id)
        ->assertStatus(200);

    $numbers = collect($response->json('data'))->pluck('student_number');
    expect($numbers)->toContain('2023000001')
        ->not->toContain('2023000002');
});

it('sorts the student list alphabetically by last name', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $bsit = studentTestDept('BSIT');
    Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['last_name' => 'Zamora', 'username' => 'u1', 'password' => 'password']));
    Student::create(array_merge(studentTestPayload($bsit, '2023000002'), ['last_name' => 'Aquino', 'username' => 'u2', 'password' => 'password']));

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/students?department_id='.$bsit->id)
        ->assertStatus(200);

    $names = collect($response->json('data'))->pluck('last_name')->values()->all();
    expect($names)->toBe(['Aquino', 'Zamora']);
});

it('filters the student list by search term across student number and names', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $bsit = studentTestDept('BSIT');
    Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['last_name' => 'Reyes', 'username' => 'u1', 'password' => 'password']));
    Student::create(array_merge(studentTestPayload($bsit, '2023000002'), ['last_name' => 'Santos', 'username' => 'u2', 'password' => 'password']));

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/students?search=Reyes')
        ->assertStatus(200);

    $names = collect($response->json('data'))->pluck('last_name');
    expect($names)->toContain('Reyes')->not->toContain('Santos');
});

it('rejects student creation from an officer', function () {
    $officer = studentTestStaff('officer', '2020200001');
    $department = studentTestDept('BSIT');

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/students', studentTestPayload($department))
        ->assertStatus(403);
});

it('lets a csg admin create a student in any department', function () {
    seedActiveSemester();
    $admin = studentTestStaff('csg_admin', '2020000001');
    $department = studentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', studentTestPayload($department))
        ->assertStatus(201)
        ->assertJsonPath('student_number', '2023105413');
});

it('lets an sc admin create a student in their own administered department', function () {
    seedActiveSemester();
    $bsit = studentTestDept('BSIT');
    $scAdmin = studentTestStaff('sc_admin', '2020300001', $bsit->id);

    $this->actingAs($scAdmin, 'sanctum')
        ->postJson('/api/students', studentTestPayload($bsit))
        ->assertStatus(201);
});

it('rejects an sc admin creating a student outside their administered department', function () {
    $bsit = studentTestDept('BSIT');
    $educ = studentTestDept('EDUC');
    $scAdmin = studentTestStaff('sc_admin', '2020300001', $bsit->id);

    $this->actingAs($scAdmin, 'sanctum')
        ->postJson('/api/students', studentTestPayload($educ))
        ->assertStatus(403);
});

it('answers 409 with update-mode data when the student number already exists', function () {
    seedActiveSemester();
    $admin = studentTestStaff('csg_admin', '2020000001');
    $payload = studentTestPayload(studentTestDept('BSIT'));

    $this->actingAs($admin, 'sanctum')->postJson('/api/students', $payload)->assertStatus(201);

    $this->actingAs($admin, 'sanctum')->postJson('/api/students', $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'student_exists')
        ->assertJsonPath('student.student_number', '2023105413');

    expect(Student::where('student_number', '2023105413')->count())->toBe(1);
});

it('re-enrolls the existing student when update_existing is sent, leaving role and name alone', function () {
    seedActiveSemester();
    $admin = studentTestStaff('csg_admin', '2020000001');
    $educ = studentTestDept('EDUC');
    $existing = Student::create(array_merge(studentTestPayload(studentTestDept('BSIT')), [
        'username' => 'u1', 'password' => 'password', 'role' => 'officer', 'status' => 'inactive',
    ]));

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', array_merge(studentTestPayload($educ), [
            'last_name' => 'Changed', 'year_level' => '4', 'section' => '4B', 'update_existing' => true,
        ]))
        ->assertStatus(200)
        ->assertJsonPath('department_id', $educ->id)
        ->assertJsonPath('status', 'active');

    $fresh = $existing->fresh();
    expect($fresh->role->value)->toBe('officer')
        ->and($fresh->last_name)->toBe('Cruz')
        ->and($fresh->section)->toBe('4B')
        ->and($fresh->semester_id)->not->toBeNull();
});

it('refuses to add or update a System Admin through single add', function () {
    seedActiveSemester();
    $admin = studentTestStaff('csg_admin', '2020000001');
    studentTestStaff('system_admin', '2023105413');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', array_merge(studentTestPayload(studentTestDept('BSIT')), ['update_existing' => true]))
        ->assertStatus(422);
});

it('answers 422 when adding a student while no semester is active', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', studentTestPayload(studentTestDept('BSIT')))
        ->assertStatus(422);
});

it('filters the student list by status', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $bsit = studentTestDept('BSIT');
    Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['username' => 'u1', 'password' => 'password']));
    Student::create(array_merge(studentTestPayload($bsit, '2023000002'), ['username' => 'u2', 'password' => 'password', 'status' => 'inactive']));

    $numbers = fn (string $q) => collect($this->actingAs($admin, 'sanctum')->getJson('/api/students?'.$q)->json('data'))->pluck('student_number');

    expect($numbers('status=inactive'))->toContain('2023000002')->not->toContain('2023000001')
        ->and($numbers('status=active'))->toContain('2023000001')->not->toContain('2023000002');
});

it('lets a csg admin edit a student in place, but not their number or role', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $student = Student::create(array_merge(studentTestPayload(studentTestDept('BSIT'), '2023000001'), ['username' => 'u1', 'password' => 'password']));

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$student->id}", ['last_name' => 'Fixed', 'student_number' => 'HACK', 'role' => 'csg_admin'])
        ->assertStatus(200)
        ->assertJsonPath('last_name', 'Fixed');

    expect($student->fresh()->student_number)->toBe('2023000001')
        ->and($student->fresh()->role->value)->toBe('student');
});

it('keeps an sc admin to students of their own department when editing', function () {
    $bsit = studentTestDept('BSIT');
    $educ = studentTestDept('EDUC');
    $scAdmin = studentTestStaff('sc_admin', '2020300001', $bsit->id);
    $own = Student::create(array_merge(studentTestPayload($bsit, '2023000001'), ['username' => 'u1', 'password' => 'password']));
    $foreign = Student::create(array_merge(studentTestPayload($educ, '2023000002'), ['username' => 'u2', 'password' => 'password']));

    $this->actingAs($scAdmin, 'sanctum')->patchJson("/api/students/{$own->id}", ['section' => '1Z'])->assertStatus(200);
    $this->actingAs($scAdmin, 'sanctum')->patchJson("/api/students/{$foreign->id}", ['section' => '1Z'])->assertStatus(403);
    $this->actingAs($scAdmin, 'sanctum')->patchJson("/api/students/{$own->id}", ['department_id' => $educ->id])->assertStatus(403);
});

it('refuses to edit or change the status of a System Admin, or your own status', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $sys = studentTestStaff('system_admin', '2020000009');

    $this->actingAs($admin, 'sanctum')->patchJson("/api/students/{$sys->id}", ['section' => '1Z'])->assertStatus(422);
    $this->actingAs($admin, 'sanctum')->patchJson("/api/students/{$sys->id}/status", ['status' => 'inactive'])->assertStatus(422);
    $this->actingAs($admin, 'sanctum')->patchJson("/api/students/{$admin->id}/status", ['status' => 'inactive'])->assertStatus(403);
});

it('toggles a student inactive (revoking tokens) and back to active in the current semester', function () {
    $semester = seedActiveSemester();
    $admin = studentTestStaff('csg_admin', '2020000001');
    $target = Student::create(array_merge(studentTestPayload(studentTestDept('BSIT'), '2023000001'), ['username' => 'u1', 'password' => 'password']));
    $target->createToken('api');

    $this->actingAs($admin, 'sanctum')->patchJson("/api/students/{$target->id}/status", ['status' => 'inactive'])
        ->assertStatus(200)->assertJsonPath('status', 'inactive');
    expect($target->tokens()->count())->toBe(0);

    $this->actingAs($admin, 'sanctum')->patchJson("/api/students/{$target->id}/status", ['status' => 'active'])
        ->assertStatus(200)->assertJsonPath('status', 'active');
    expect($target->fresh()->semester_id)->toBe($semester->id);
});

it('validates required fields on student creation', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_number', 'last_name', 'first_name', 'middle_name', 'department_id', 'year_level']);
});
