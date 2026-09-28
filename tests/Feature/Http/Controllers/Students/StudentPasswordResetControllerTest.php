<?php

use App\Models\Department;
use App\Models\PasswordResetCode;
use App\Models\Student;
use App\Models\StudentPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function pwResetDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function pwResetPerson(string $role, string $number, string $dept = 'CCS', ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $number,
        'last_name' => 'Person', 'first_name' => ucfirst($role),
        'department_id' => pwResetDept($dept)->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'pw'.$number,
        'password' => 'Chosen-Password1!',
        'role' => $role,
        'must_change_password' => false,
        // EnsurePhotoHasBeenUploaded blocks any Student-role actor with no
        // photo_path from the whole API, regardless of what the route
        // itself would authorize. That gate is unrelated to what these
        // tests exercise (StudentPolicy::resetPassword), so every actor
        // here is given a photo up front — otherwise a plain 'student'
        // actor would get 423 (photo gate) before ever reaching the 403
        // (role check) the test is actually asserting.
        'photo_path' => 'students/photos/pw'.$number.'.jpg',
    ]);
}

function pwResetCall(Student $actor, Student $target)
{
    return test()->actingAs($actor, 'sanctum')->postJson("/api/students/{$target->id}/reset-password");
}

it('rejects an unauthenticated reset', function () {
    $target = pwResetPerson('student', '2020300001');

    $this->postJson("/api/students/{$target->id}/reset-password")->assertStatus(401);
});

it('rejects a plain student and an officer', function () {
    $target = pwResetPerson('student', '2020300001');

    pwResetCall(pwResetPerson('student', '2020300002'), $target)->assertStatus(403);
    pwResetCall(pwResetPerson('officer', '2020200001'), $target)->assertStatus(403);
});

it('resets the password to the default and forces a change at next login', function () {
    $admin = pwResetPerson('csg_admin', '2020000001');
    $target = pwResetPerson('student', '2020300001');
    $target->createToken('api');

    pwResetCall($admin, $target)->assertOk();

    $target->refresh();

    expect(Hash::check('password123', $target->password))->toBeTrue()
        ->and(Hash::check('Chosen-Password1!', $target->password))->toBeFalse()
        ->and($target->must_change_password)->toBeTrue()
        ->and($target->tokens()->count())->toBe(0);
});

it('lets the student sign in with the default password and be sent to change it', function () {
    $admin = pwResetPerson('csg_admin', '2020000001');
    $target = pwResetPerson('student', '2020300001');

    pwResetCall($admin, $target)->assertOk();

    $this->postJson('/api/auth/login', ['username' => $target->username, 'password' => 'password123'])
        ->assertOk()
        ->assertJsonPath('must_change_password', true);
});

it('writes an audit row saying who reset whose password', function () {
    $admin = pwResetPerson('csg_admin', '2020000001');
    $target = pwResetPerson('student', '2020300001');

    pwResetCall($admin, $target)->assertOk();

    $row = StudentPasswordReset::first();

    expect(StudentPasswordReset::count())->toBe(1)
        ->and($row->student_id)->toBe($target->id)
        ->and($row->reset_by)->toBe($admin->id);
});

it('kills a forgot-password code that was still waiting to be used', function () {
    $admin = pwResetPerson('csg_admin', '2020000001');
    $target = pwResetPerson('student', '2020300001');

    $code = PasswordResetCode::create([
        'student_id' => $target->id, 'code' => '123456', 'expires_at' => now()->addMinutes(5),
    ]);

    pwResetCall($admin, $target)->assertOk();

    expect($code->fresh()->status()->value)->toBe('expired');
});

it('lets a system admin reset anyone else, including a csg admin', function () {
    $system = pwResetPerson('system_admin', '2020000009');

    pwResetCall($system, pwResetPerson('csg_admin', '2020000001'))->assertOk();
    pwResetCall($system, pwResetPerson('sc_admin', '2020100001'))->assertOk();
});

it('never lets anyone reset their own password this way', function () {
    $system = pwResetPerson('system_admin', '2020000009');
    $csg = pwResetPerson('csg_admin', '2020000001');

    pwResetCall($system, $system)->assertStatus(403);
    pwResetCall($csg, $csg)->assertStatus(403);
    expect(StudentPasswordReset::count())->toBe(0);
});

it('lets a csg admin reset sc admins, officers and students of any department, but never a csg or system admin', function () {
    $csg = pwResetPerson('csg_admin', '2020000001');

    pwResetCall($csg, pwResetPerson('sc_admin', '2020100001', 'BSBA'))->assertOk();
    pwResetCall($csg, pwResetPerson('officer', '2020200001', 'BSIT'))->assertOk();
    pwResetCall($csg, pwResetPerson('student', '2020300001', 'EDUC'))->assertOk();

    pwResetCall($csg, pwResetPerson('csg_admin', '2020000002'))->assertStatus(403);
    pwResetCall($csg, pwResetPerson('system_admin', '2020000003'))->assertStatus(403);
});

it('lets an sc admin reset only students and officers of the department they administer', function () {
    $bsit = pwResetDept('BSIT');
    $sc = pwResetPerson('sc_admin', '2020100001', 'BSIT', $bsit->id);

    pwResetCall($sc, pwResetPerson('student', '2020300001', 'BSIT'))->assertOk();
    pwResetCall($sc, pwResetPerson('officer', '2020200001', 'BSIT'))->assertOk();

    // Another department.
    $other = pwResetPerson('student', '2020300002', 'BSBA');
    pwResetCall($sc, $other)->assertStatus(403);
    expect(Hash::check('Chosen-Password1!', $other->fresh()->password))->toBeTrue();

    // Same department, but not below an SC admin in rank.
    pwResetCall($sc, pwResetPerson('sc_admin', '2020100002', 'BSIT', $bsit->id))->assertStatus(403);
    pwResetCall($sc, pwResetPerson('csg_admin', '2020000001', 'BSIT'))->assertStatus(403);
    pwResetCall($sc, pwResetPerson('system_admin', '2020000009', 'BSIT'))->assertStatus(403);
});

it('lets an sc admin with no administered department reset nobody', function () {
    $sc = pwResetPerson('sc_admin', '2020100001', 'BSIT', null);

    pwResetCall($sc, pwResetPerson('student', '2020300001', 'BSIT'))->assertStatus(403);
});

it('returns 404 for a student that does not exist', function () {
    $admin = pwResetPerson('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')->postJson('/api/students/999999/reset-password')->assertStatus(404);
});
