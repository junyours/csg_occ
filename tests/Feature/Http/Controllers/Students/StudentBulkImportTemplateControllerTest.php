<?php

use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function templateApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function templateApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => templateApiDept('CCS')->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects an unauthenticated template download request', function () {
    $this->getJson('/api/students/bulk-import-template')->assertStatus(401);
});

it('rejects an officer downloading the import template', function () {
    $officer = templateApiStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/students/bulk-import-template')
        ->assertStatus(403);
});

it('lets a csg admin download the import template', function () {
    $admin = templateApiStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->get('/api/students/bulk-import-template')
        ->assertOk()
        ->assertDownload('student-import-template.xlsx');
});
