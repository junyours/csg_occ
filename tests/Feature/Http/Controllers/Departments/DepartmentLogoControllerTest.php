<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function logoCtrlDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function logoCtrlStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => logoCtrlDept('CCS')->id,
        'username' => 'lc'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects logo upload from a non-admin role', function () {
    Storage::fake('public');
    $officer = logoCtrlStaff('officer', '2020200001');
    $dept = logoCtrlDept('BSIT');

    $this->actingAs($officer, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertStatus(403);
});

it('lets a csg admin upload a department logo', function () {
    Storage::fake('public');
    $admin = logoCtrlStaff('csg_admin', '2020000001');
    $dept = logoCtrlDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png', 900, 900),
        ])
        ->assertStatus(200)
        ->assertJsonPath('logo_url', fn ($url) => ! empty($url));
});

it('lets a system admin upload a department logo', function () {
    Storage::fake('public');
    $admin = logoCtrlStaff('system_admin', '2020000002');
    $dept = logoCtrlDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertStatus(200);
});

it('rejects an sc admin uploading a department logo', function () {
    Storage::fake('public');
    $bsit = logoCtrlDept('BSIT');
    $admin = Student::create([
        'student_number' => '2020000003',
        'last_name' => 'Staff', 'first_name' => 'ScAdmin',
        'department_id' => $bsit->id,
        'sc_admin_department_id' => $bsit->id,
        'username' => 'lc2020000003',
        'password' => 'password',
        'role' => 'sc_admin',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$bsit->id}/logo", [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])
        ->assertStatus(403);
});

it('rejects an oversized or non-image logo file', function () {
    Storage::fake('public');
    $admin = logoCtrlStaff('csg_admin', '2020000001');
    $dept = logoCtrlDept('BSIT');

    $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", [
            'logo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('logo');
});

it('replaces an existing logo on re-upload', function () {
    Storage::fake('public');
    $admin = logoCtrlStaff('csg_admin', '2020000001');
    $dept = logoCtrlDept('BSIT');

    $first = $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", ['logo' => UploadedFile::fake()->image('a.png', 900, 900)])
        ->assertStatus(200)
        ->json('logo_path');

    $this->actingAs($admin, 'sanctum')
        ->post("/api/departments/{$dept->id}/logo", ['logo' => UploadedFile::fake()->image('b.png', 900, 900)])
        ->assertStatus(200);

    Storage::disk('public')->assertMissing($first);
});
