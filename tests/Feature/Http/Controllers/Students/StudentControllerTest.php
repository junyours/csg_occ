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
    $admin = studentTestStaff('csg_admin', '2020000001');
    $department = studentTestDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', studentTestPayload($department))
        ->assertStatus(201)
        ->assertJsonPath('student_number', '2023105413');
});

it('lets an sc admin create a student in their own administered department', function () {
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

it('rejects a duplicate student number', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');
    $department = studentTestDept('BSIT');
    $payload = studentTestPayload($department);

    $this->actingAs($admin, 'sanctum')->postJson('/api/students', $payload)->assertStatus(201);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('student_number');
});

it('validates required fields on student creation', function () {
    $admin = studentTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/students', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['student_number', 'last_name', 'first_name', 'middle_name', 'department_id', 'year_level']);
});
