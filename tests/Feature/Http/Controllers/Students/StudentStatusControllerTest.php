<?php

use App\Domain\Enums\Role;
use App\Models\Department;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "statusTest*" — Pest loads every test file's top-level
// functions into one process, so names must stay unique suite-wide.
function statusTestAccount(string $role, string $number, array $overrides = []): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    return Student::create(array_merge([
        'student_number' => $number,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'username' => 'user'.$number,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
        'photo_path' => 'students/placeholder.jpg',
    ], $overrides));
}

it('reactivates a student and enrolls them in the current semester', function () {
    $semester = seedActiveSemester();
    $admin = statusTestAccount(Role::SystemAdmin->value, '1000000001');
    $student = statusTestAccount(Role::Student->value, '2023000001', ['status' => 'inactive', 'semester_id' => null]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$student->id}/status", ['status' => 'active'])
        ->assertStatus(200);

    $student->refresh();
    expect($student->status)->toBe('active')
        ->and($student->semester_id)->toBe($semester->id);
});

it('deactivates a student and revokes their tokens', function () {
    seedActiveSemester();
    $admin = statusTestAccount(Role::SystemAdmin->value, '1000000001');
    $student = statusTestAccount(Role::Student->value, '2023000001');
    $student->createToken('api');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$student->id}/status", ['status' => 'inactive'])
        ->assertStatus(200);

    expect($student->fresh()->status)->toBe('inactive')
        ->and($student->tokens()->count())->toBe(0);
});

it('refuses to reactivate when no semester is active', function () {
    $admin = statusTestAccount(Role::SystemAdmin->value, '1000000001');
    $student = statusTestAccount(Role::Student->value, '2023000001', ['status' => 'inactive']);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$student->id}/status", ['status' => 'active'])
        ->assertStatus(422);
});

it('refuses to change a System Admin row', function () {
    seedActiveSemester();
    $admin = statusTestAccount(Role::SystemAdmin->value, '1000000001');
    $other = statusTestAccount(Role::SystemAdmin->value, '1000000002');

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/students/{$other->id}/status", ['status' => 'inactive'])
        ->assertStatus(422);

    expect($other->fresh()->status)->toBe('active');
});

it('does not let a plain student change anyone\'s status', function () {
    seedActiveSemester();
    $caller = statusTestAccount(Role::Student->value, '2023000009');
    $target = statusTestAccount(Role::Student->value, '2023000001');

    $this->actingAs($caller, 'sanctum')
        ->patchJson("/api/students/{$target->id}/status", ['status' => 'inactive'])
        ->assertStatus(403);
});
