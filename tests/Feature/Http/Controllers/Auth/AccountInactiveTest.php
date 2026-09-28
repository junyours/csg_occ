<?php

use App\Domain\Enums\Role;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Prefixed "inactiveTest*" — Pest loads every test file's top-level
// functions into one process, so names must stay unique suite-wide.
function inactiveTestAccount(array $overrides = []): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    return Student::create(array_merge([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'username' => 'jcruz',
        'password' => 'correct-password',
        'role' => Role::Student,
        'must_change_password' => false,
        'photo_path' => 'students/placeholder.jpg',
    ], $overrides));
}

it('refuses login for an inactive account with the correct password', function () {
    inactiveTestAccount(['status' => 'inactive']);

    $this->postJson('/api/auth/login', ['username' => 'jcruz', 'password' => 'correct-password'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'This account is inactive. Please contact the CSG or System Admin.');
});

it('does not reveal an inactive account when the password is wrong', function () {
    inactiveTestAccount(['status' => 'inactive']);

    $this->postJson('/api/auth/login', ['username' => 'jcruz', 'password' => 'wrong-password'])
        ->assertStatus(401);
});

it('still lets an inactive System Admin log in', function () {
    inactiveTestAccount(['role' => Role::SystemAdmin, 'status' => 'inactive', 'username' => 'sysadmin']);

    $this->postJson('/api/auth/login', ['username' => 'sysadmin', 'password' => 'correct-password'])
        ->assertStatus(200)
        ->assertJsonStructure(['token']);
});

it('answers 401 on every request once an account has been deactivated', function () {
    $student = inactiveTestAccount();

    $this->actingAs($student, 'sanctum')->getJson('/api/auth/me')->assertStatus(200);

    $student->update(['status' => 'inactive']);

    $this->actingAs($student->fresh(), 'sanctum')->getJson('/api/auth/me')->assertStatus(401);
});

it('never blocks an inactive System Admin on an authenticated request', function () {
    $admin = inactiveTestAccount(['role' => Role::SystemAdmin, 'status' => 'inactive', 'username' => 'sysadmin']);

    $this->actingAs($admin, 'sanctum')->getJson('/api/auth/me')->assertStatus(200);
});
