<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function photoGateDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function photoGateStudent(string $studentNumber, string $role = 'student', ?string $photoPath = 'students/placeholder.jpg'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => photoGateDept('CCS')->id,
        'username' => 'pg'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
        'photo_path' => $photoPath,
    ]);
}

/*
 * EnsurePhotoHasBeenUploaded is now registered on the whole authenticated
 * API (routes/api.php's photo.uploaded group, nested inside
 * password.changed) rather than just the QR route — the exact same scope
 * EnsurePasswordHasBeenChanged already has. StudentQrControllerTest still
 * covers the QR-specific behavior (own-QR-vs-someone-else's, admin
 * printing a student's card before they've logged in); these tests cover
 * the broader gate itself: that it now reaches routes far away from the
 * QR endpoint, and that the one route needed to clear it stays open.
 */

it('blocks a photo-less student from an unrelated gated endpoint', function () {
    $student = photoGateStudent('2020300001', photoPath: null);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertStatus(423)
        ->assertJson(['message' => 'Upload a verification photo before viewing your QR code.']);
});

it('blocks a photo-less student from my-attendance-history too', function () {
    $student = photoGateStudent('2020300001', photoPath: null);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/my-attendance-history')
        ->assertStatus(423);
});

it('lets a student with a photo on file reach the dashboard', function () {
    $student = photoGateStudent('2020300001', photoPath: 'students/1/avatar.jpg');

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertStatus(200);
});

it('does not block an admin with no photo of their own from the dashboard', function () {
    $admin = photoGateStudent('2020000001', 'csg_admin', photoPath: null);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/dashboard')
        ->assertStatus(200);
});

it('never blocks a photo-less student from uploading their own photo', function () {
    Storage::fake('public');
    $student = photoGateStudent('2020300001', photoPath: null);

    // If this route were nested inside photo.uploaded like everything
    // else, a photo-less student could never clear the gate at all —
    // the same reason /auth/change-password sits outside
    // password.changed.
    $this->actingAs($student, 'sanctum')
        ->post("/api/students/{$student->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.jpg', 900, 900),
        ])
        ->assertStatus(200);
});

it('still lets a student with no photo check /auth/me', function () {
    $student = photoGateStudent('2020300001', photoPath: null);

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/auth/me')
        ->assertStatus(200);
});

it('still lets a student with no photo log out', function () {
    $student = photoGateStudent('2020300001', photoPath: null);
    $student->password = 'correct-password';
    $student->save();

    $token = $this->postJson('/api/auth/login', [
        'username' => $student->username,
        'password' => 'correct-password',
    ])->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout')
        ->assertStatus(200);
});
