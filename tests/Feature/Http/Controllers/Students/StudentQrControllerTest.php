<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function qrCtrlDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function qrCtrlStudent(string $studentNumber, string $role = 'student', ?int $scAdminDepartmentId = null, ?string $photoPath = 'students/placeholder.jpg'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => qrCtrlDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'qc'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Every helper-created student has a photo on file by default, so
        // existing tests below keep exercising what they were written to
        // exercise (auth/authorization) rather than tripping the newer
        // EnsurePhotoHasBeenUploaded gate by accident. Tests for that gate
        // itself pass `photoPath: null` explicitly.
        'photo_path' => $photoPath,
    ]);
}

it('rejects unauthenticated qr requests', function () {
    $student = qrCtrlStudent('2020300001');

    $this->getJson("/api/students/{$student->id}/qr")
    ->assertStatus(401);
});

it('lets a student view their own qr as a png', function () {
    $student = qrCtrlStudent('2020300001');

    $this->actingAs($student, 'sanctum')
        ->get("/api/students/{$student->id}/qr")
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline');
});

it('rejects a student viewing someone else\'s qr', function () {
    $student = qrCtrlStudent('2020300001');
    $other = qrCtrlStudent('2020300002');

    $this->actingAs($student, 'sanctum')
        ->get("/api/students/{$other->id}/qr")
        ->assertStatus(403);
});

it('lets a csg admin view any student\'s qr', function () {
    $admin = qrCtrlStudent('2020000001', 'csg_admin');
    $target = qrCtrlStudent('2020300001');

    $this->actingAs($admin, 'sanctum')
        ->get("/api/students/{$target->id}/qr")
        ->assertStatus(200);
});

it('sets an attachment disposition when download=1 is passed', function () {
    $student = qrCtrlStudent('2020300001');

    $this->actingAs($student, 'sanctum')
        ->get("/api/students/{$student->id}/qr?download=1")
        ->assertStatus(200)
        ->assertHeader('Content-Disposition', 'attachment; filename="qr-2020300001.png"');
});

/*
 * EnsurePhotoHasBeenUploaded — the same never-trust-the-client-alone
 * treatment as EnsurePasswordHasBeenChanged (see that middleware's test
 * coverage under Auth), scoped to this one route. A student without a
 * photo on file can't fetch their own QR even by calling the API
 * directly, so refreshing the page — or any other way of skipping the
 * frontend's own client-side gate — can never quietly bypass it.
 */

it('blocks a student with no photo from viewing their own qr', function () {
    $student = qrCtrlStudent('2020300001', photoPath: null);

    $this->actingAs($student, 'sanctum')
        ->getJson("/api/students/{$student->id}/qr")
        ->assertStatus(423)
        ->assertJson(['message' => 'Upload a verification photo before viewing your QR code.']);
});

it('lets a student with a photo on file view their own qr', function () {
    $student = qrCtrlStudent('2020300001', photoPath: 'students/1/avatar.jpg');

    $this->actingAs($student, 'sanctum')
        ->get("/api/students/{$student->id}/qr")
        ->assertStatus(200);
});

it('does not block a csg admin who has no photo of their own from viewing a student\'s qr', function () {
    $admin = qrCtrlStudent('2020000001', 'csg_admin', photoPath: null);
    $target = qrCtrlStudent('2020300001');

    $this->actingAs($admin, 'sanctum')
        ->get("/api/students/{$target->id}/qr")
        ->assertStatus(200);
});

it('lets an admin view a student\'s qr even when that student has no photo yet', function () {
    // Spec §4.5: admins pre-produce ID cards before a student has ever
    // logged in or uploaded a photo — the gate only ever applies to a
    // student viewing their own QR, never to an admin viewing someone
    // else's, regardless of whether the target has a photo.
    $admin = qrCtrlStudent('2020000001', 'csg_admin');
    $target = qrCtrlStudent('2020300001', photoPath: null);

    $this->actingAs($admin, 'sanctum')
        ->get("/api/students/{$target->id}/qr")
        ->assertStatus(200);
});
