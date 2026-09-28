<?php

use App\Domain\Enums\PasswordResetCodeStatus;
use App\Domain\Enums\Role;
use App\Models\Department;
use App\Models\PasswordResetCode;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resetCodesApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

/**
 * @param  array<string, mixed>  $extra
 */
function resetCodesApiUser(Role $role, string $studentNumber, string $deptCode = 'CCS', array $extra = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => ucfirst($role->value),
        'department_id' => resetCodesApiDept($deptCode)->id,
        'username' => $studentNumber,
        'password' => 'Password-1!',
        'role' => $role,
        'must_change_password' => false,
    ], $extra));
}

/** An SC Admin administering the given department. */
function resetCodesApiScAdmin(string $studentNumber, string $adminOf, array $extra = []): Student
{
    return resetCodesApiUser(Role::ScAdmin, $studentNumber, $adminOf, array_merge([
        'sc_admin_department_id' => resetCodesApiDept($adminOf)->id,
    ], $extra));
}

/**
 * A live (pending) code by default; override to make it something else.
 *
 * @param  array<string, mixed>  $overrides
 */
function resetCodesApiCode(Student $student, array $overrides = []): PasswordResetCode
{
    return PasswordResetCode::create(array_merge([
        'student_id' => $student->id,
        'code' => '123456',
        'expires_at' => now()->addMinutes(5),
    ], $overrides));
}

/** Student numbers of every row the response returned, sorted for stable comparison. */
function resetCodesApiNumbers($response): array
{
    return collect($response->json('data'))->pluck('student_number')->sort()->values()->all();
}

// ---------------------------------------------------------------------------
// Who can open the screen
// ---------------------------------------------------------------------------

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/password-reset-codes')->assertStatus(401);
});

it('rejects a plain student', function () {
    $student = resetCodesApiUser(Role::Student, '2023-1-00001', 'CCS', ['photo_path' => 'students/p.jpg']);

    $this->actingAs($student, 'sanctum')->getJson('/api/password-reset-codes')->assertStatus(403);
});

it('rejects an officer', function () {
    $officer = resetCodesApiUser(Role::Officer, '2023-1-00002');

    $this->actingAs($officer, 'sanctum')->getJson('/api/password-reset-codes')->assertStatus(403);
});

it('blocks an admin who has not yet changed their default password', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00001', 'CCS', ['must_change_password' => true]);

    $this->actingAs($admin, 'sanctum')->getJson('/api/password-reset-codes')->assertStatus(403);
});

// ---------------------------------------------------------------------------
// What each admin can see
// ---------------------------------------------------------------------------

it('shows a system admin every code, whoever it is for', function () {
    $sysAdmin = resetCodesApiUser(Role::SystemAdmin, '2020-1-00001');

    foreach ([
        [Role::Student, '2023-1-00010'],
        [Role::Officer, '2023-1-00011'],
        [Role::ScAdmin, '2023-1-00012'],
        [Role::CsgAdmin, '2023-1-00013'],
        [Role::SystemAdmin, '2023-1-00014'],
    ] as [$role, $number]) {
        resetCodesApiCode(resetCodesApiUser($role, $number));
    }

    $response = $this->actingAs($sysAdmin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(resetCodesApiNumbers($response))->toBe([
        '2023-1-00010', '2023-1-00011', '2023-1-00012', '2023-1-00013', '2023-1-00014',
    ]);
});

it('shows a csg admin students, officers and sc admins from any department — but never a csg or system admin', function () {
    $csgAdmin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00002');

    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00020', 'CCS'));
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00021', 'CBA'));
    resetCodesApiCode(resetCodesApiUser(Role::Officer, '2023-1-00022', 'CBA'));
    resetCodesApiCode(resetCodesApiUser(Role::ScAdmin, '2023-1-00023', 'CBA'));
    // Lateral / upward takeover must stay impossible:
    resetCodesApiCode(resetCodesApiUser(Role::CsgAdmin, '2023-1-00024'));
    resetCodesApiCode(resetCodesApiUser(Role::SystemAdmin, '2023-1-00025'));

    $response = $this->actingAs($csgAdmin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(resetCodesApiNumbers($response))->toBe([
        '2023-1-00020', '2023-1-00021', '2023-1-00022', '2023-1-00023',
    ]);
});

it('shows an sc admin only the students and officers of the department they administer', function () {
    $scAdmin = resetCodesApiScAdmin('2020-1-00003', 'CCS');

    // Visible: same department, plain student / officer.
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00030', 'CCS'));
    resetCodesApiCode(resetCodesApiUser(Role::Officer, '2023-1-00031', 'CCS'));

    // Not visible: another department.
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00032', 'CBA'));

    // Not visible: same department, but they outrank (or equal) an SC admin.
    // If these leaked, an SC admin could request a reset for them, read the
    // code off this screen, and take the account over.
    resetCodesApiCode(resetCodesApiUser(Role::ScAdmin, '2023-1-00033', 'CCS'));
    resetCodesApiCode(resetCodesApiUser(Role::CsgAdmin, '2023-1-00034', 'CCS'));
    resetCodesApiCode(resetCodesApiUser(Role::SystemAdmin, '2023-1-00035', 'CCS'));

    $response = $this->actingAs($scAdmin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(resetCodesApiNumbers($response))->toBe(['2023-1-00030', '2023-1-00031']);
});

it('bases an sc admin\'s scope on the department they administer, not the one they belong to', function () {
    // Enrolled in CBA, but administers CCS.
    $scAdmin = resetCodesApiUser(Role::ScAdmin, '2020-1-00004', 'CBA', [
        'sc_admin_department_id' => resetCodesApiDept('CCS')->id,
    ]);

    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00040', 'CCS'));
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00041', 'CBA'));

    $response = $this->actingAs($scAdmin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(resetCodesApiNumbers($response))->toBe(['2023-1-00040']);
});

it('shows an sc admin with no administered department nothing at all', function () {
    $scAdmin = resetCodesApiUser(Role::ScAdmin, '2020-1-00005'); // sc_admin_department_id stays null

    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00050'));

    $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// What a row carries
// ---------------------------------------------------------------------------

it('returns a live code with its expiry and the student it belongs to', function () {
    $this->freezeSecond();
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00006');
    $student = resetCodesApiUser(Role::Student, '2023-1-00060', 'CBA');
    resetCodesApiCode($student, ['code' => '048213']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonPath('now', now()->toIso8601String())
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student_number', '2023-1-00060')
        ->assertJsonPath('data.0.department_code', 'CBA')
        ->assertJsonPath('data.0.role', 'student')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.code', '048213') // leading zero survives
        ->assertJsonPath('data.0.attempts', 0)
        ->assertJsonPath('data.0.expires_at', now()->addMinutes(5)->toIso8601String());
});

it('lists the newest code first', function () {
    $admin = resetCodesApiUser(Role::SystemAdmin, '2020-1-00007');
    $older = resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00070'));
    $older->forceFill(['created_at' => now()->subMinute()])->save();
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00071'));

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(collect($response->json('data'))->pluck('student_number')->all())
        ->toBe(['2023-1-00071', '2023-1-00070']);
});

it('only ever puts a usable code on the wire — a locked one comes back without it', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00008');
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00080'), ['attempts' => 5, 'code' => '999999']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonPath('data.0.status', 'locked')
        ->assertJsonPath('data.0.code', null);
});

it('keeps a code that has been verified but whose owner is still setting a new password', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00009');
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00090'), [
        'used_at' => now()->subMinute(),
        'reset_token_hash' => hash('sha256', 'a-live-token'),
        'reset_token_expires_at' => now()->addMinutes(4),
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'used')
        ->assertJsonPath('data.0.code', null);
});

// ---------------------------------------------------------------------------
// Loading the list never deletes anything
// ---------------------------------------------------------------------------

it('leaves used and expired codes in place when the list is loaded, showing them as such', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00010');
    $student = resetCodesApiUser(Role::Student, '2023-1-00100');

    resetCodesApiCode($student, ['expires_at' => now()->subSecond(), 'code' => '111111']);
    resetCodesApiCode($student, ['used_at' => now()->subMinutes(2)]);
    resetCodesApiCode($student);

    $response = $this->actingAs($admin, 'sanctum')->getJson('/api/password-reset-codes')->assertOk();

    expect(PasswordResetCode::count())->toBe(3)
        ->and(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe(['expired', 'pending', 'used'])
        // Only the live one carries a code.
        ->and(collect($response->json('data'))->pluck('code')->filter()->values()->all())->toBe(['123456']);
});

// ---------------------------------------------------------------------------
// "Clear used & expired"
// ---------------------------------------------------------------------------

it('rejects an unauthenticated clear', function () {
    $this->deleteJson('/api/password-reset-codes')->assertStatus(401);
});

it('rejects a student and an officer clearing codes', function () {
    $student = resetCodesApiUser(Role::Student, '2023-1-00101', 'CCS', ['photo_path' => 'students/p.jpg']);
    $officer = resetCodesApiUser(Role::Officer, '2023-1-00102');

    $this->actingAs($student, 'sanctum')->deleteJson('/api/password-reset-codes')->assertStatus(403);
    $this->actingAs($officer, 'sanctum')->deleteJson('/api/password-reset-codes')->assertStatus(403);
});

it('removes used and expired codes on request, and keeps the rest', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00012');
    $student = resetCodesApiUser(Role::Student, '2023-1-00103');

    // Should be swept away:
    $expired = resetCodesApiCode($student, ['expires_at' => now()->subSecond()]);
    $finished = resetCodesApiCode($student, [
        'used_at' => now()->subMinutes(2), 'reset_token_hash' => null, 'reset_token_expires_at' => null,
    ]);
    $abandoned = resetCodesApiCode($student, [
        'used_at' => now()->subMinutes(9),
        'reset_token_hash' => hash('sha256', 'stale-token'),
        'reset_token_expires_at' => now()->subSecond(),
    ]);

    // Should stay:
    $pending = resetCodesApiCode($student);
    $locked = resetCodesApiCode($student, ['attempts' => 5]);
    $midReset = resetCodesApiCode($student, [
        'used_at' => now()->subMinute(),
        'reset_token_hash' => hash('sha256', 'live-token'),
        'reset_token_expires_at' => now()->addMinutes(4),
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->deleteJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonPath('deleted', 3);

    expect(PasswordResetCode::whereKey([$expired->id, $finished->id, $abandoned->id])->count())->toBe(0)
        ->and(PasswordResetCode::whereKey([$pending->id, $locked->id, $midReset->id])->count())->toBe(3)
        // The response is already the refreshed list, so the screen needs no second call.
        ->and(collect($response->json('data'))->pluck('status')->sort()->values()->all())
        ->toBe(['locked', 'pending', 'used'])
        ->and($response->json('now'))->toBeString();
});

it('reports zero when there is nothing to clear', function () {
    $admin = resetCodesApiUser(Role::CsgAdmin, '2020-1-00013');
    resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00104'));

    $this->actingAs($admin, 'sanctum')
        ->deleteJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonPath('deleted', 0)
        ->assertJsonCount(1, 'data');
});

it('only clears the dead codes an sc admin is allowed to see', function () {
    $scAdmin = resetCodesApiScAdmin('2020-1-00014', 'CCS');
    $ownStudent = resetCodesApiUser(Role::Student, '2023-1-00105', 'CCS');
    $otherStudent = resetCodesApiUser(Role::Student, '2023-1-00106', 'CBA');
    $higherRank = resetCodesApiUser(Role::CsgAdmin, '2023-1-00107', 'CCS');

    $ownExpired = resetCodesApiCode($ownStudent, ['expires_at' => now()->subMinute()]);
    $otherExpired = resetCodesApiCode($otherStudent, ['expires_at' => now()->subMinute()]);
    $higherExpired = resetCodesApiCode($higherRank, ['expires_at' => now()->subMinute()]);

    $this->actingAs($scAdmin, 'sanctum')
        ->deleteJson('/api/password-reset-codes')
        ->assertOk()
        ->assertJsonPath('deleted', 1);

    expect(PasswordResetCode::whereKey($ownExpired->id)->exists())->toBeFalse()
        ->and(PasswordResetCode::whereKey($otherExpired->id)->exists())->toBeTrue()
        ->and(PasswordResetCode::whereKey($higherExpired->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// The status rules themselves
// ---------------------------------------------------------------------------

it('classifies a code as pending, locked, used or expired', function () {
    $this->freezeSecond();
    $student = resetCodesApiUser(Role::Student, '2023-1-00120');

    expect(resetCodesApiCode($student)->status())->toBe(PasswordResetCodeStatus::Pending)
        ->and(resetCodesApiCode($student, ['attempts' => 4])->status())->toBe(PasswordResetCodeStatus::Pending)
        ->and(resetCodesApiCode($student, ['attempts' => 5])->status())->toBe(PasswordResetCodeStatus::Locked)
        ->and(resetCodesApiCode($student, ['used_at' => now()])->status())->toBe(PasswordResetCodeStatus::Used)
        ->and(resetCodesApiCode($student, ['expires_at' => now()->subSecond()])->status())->toBe(PasswordResetCodeStatus::Expired);
});

it('treats a code as expired at exactly its 5-minute mark', function () {
    $this->freezeSecond();
    $code = resetCodesApiCode(resetCodesApiUser(Role::Student, '2023-1-00121'));

    expect($code->status(now()->addSeconds(299)))->toBe(PasswordResetCodeStatus::Pending)
        ->and($code->status(now()->addMinutes(5)))->toBe(PasswordResetCodeStatus::Expired);
});

it('lets an expired code win over a burned one — both are just dead', function () {
    $this->freezeSecond();
    $student = resetCodesApiUser(Role::Student, '2023-1-00122');

    expect(resetCodesApiCode($student, ['attempts' => 5, 'expires_at' => now()->subSecond()])->status())
        ->toBe(PasswordResetCodeStatus::Expired);
});
