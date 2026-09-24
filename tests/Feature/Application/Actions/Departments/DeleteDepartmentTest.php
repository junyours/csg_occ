<?php

use App\Application\Actions\Departments\DeleteDepartment;
use App\Domain\Exceptions\DepartmentHasStudentsException;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('deletes a department with no students', function () {
    $department = Department::create(['name' => 'Unused', 'code' => 'UNU']);

    (new DeleteDepartment)($department);

    expect(Department::find($department->id))->toBeNull();
});

it('refuses to delete a department a student belongs to', function () {
    $department = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);
    Student::create([
        'student_number' => '2020300001',
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => $department->id,
        'username' => 'dd2020300001',
        'password' => 'password',
        'role' => 'student',
    ]);

    (new DeleteDepartment)($department);
})->throws(DepartmentHasStudentsException::class);

it('refuses to delete a department an sc admin is scoped to, even with no home students', function () {
    $bsit = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);
    $ccs = Department::create(['name' => 'CCS', 'code' => 'CCS']);
    Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'Sc',
        'department_id' => $ccs->id,
        'sc_admin_department_id' => $bsit->id,
        'username' => 'dd2020000001',
        'password' => 'password',
        'role' => 'sc_admin',
    ]);

    (new DeleteDepartment)($bsit);
})->throws(DepartmentHasStudentsException::class);

it('leaves the department untouched when the guard blocks deletion', function () {
    $department = Department::create(['name' => 'BSIT', 'code' => 'BSIT']);
    Student::create([
        'student_number' => '2020300001',
        'last_name' => 'Test', 'first_name' => 'Student',
        'department_id' => $department->id,
        'username' => 'dd2020300002',
        'password' => 'password',
        'role' => 'student',
    ]);

    try {
        (new DeleteDepartment)($department);
    } catch (DepartmentHasStudentsException) {
        // expected
    }

    expect(Department::find($department->id))->not->toBeNull();
});

it('deletes the stored logo file along with the department', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Unused', 'code' => 'UNU']);
    $path = 'department-logos/test-logo.png';
    Storage::disk('public')->put($path, 'dummy-logo-content');
    $department->update(['logo_path' => $path]);

    (new DeleteDepartment)($department);

    Storage::disk('public')->assertMissing($path);
});
