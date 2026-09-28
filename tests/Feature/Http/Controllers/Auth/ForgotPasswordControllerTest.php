<?php

use App\Application\Actions\Auth\RequestPasswordResetCode;
use App\Domain\Enums\Role;
use App\Models\Department;
use App\Models\PasswordResetCode;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

const FORGOT_API_OLD_PASSWORD = 'Old-Password1!';
const FORGOT_API_NEW_PASSWORD = 'Brand-New-Password1!';

function forgotApiStudent(array $overrides = []): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    return Student::create(array_merge([
        'student_number' => '2023-1-05413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'username' => '2023-1-05413',
        'password' => FORGOT_API_OLD_PASSWORD,
        'role' => Role::Student,
        'must_change_password' => false,
    ], $overrides));
}

/** Step 1 — the student asks for a code. */
function forgotApiRequestCode(string $studentNumber)
{
    return test()->postJson('/api/auth/forgot-password', ['student_number' => $studentNumber]);
}

/** The plain-text code an admin would read off their screen. */
function forgotApiCodeFor(Student $student): string
{
    return PasswordResetCode::where('student_id', $student->id)->latest('id')->firstOrFail()->code;
}

/** A code guaranteed to differ from the real one. */
function forgotApiWrongCode(string $realCode): string
{
    return $realCode === '000000' ? '111111' : '000000';
}

/** Step 2 — the student types the code. */
function forgotApiVerify(string $studentNumber, string $code)
{
    return test()->postJson('/api/auth/forgot-password/verify', [
        'student_number' => $studentNumber,
        'code' => $code,
    ]);
}

/** Steps 1 + 2 in one go; returns the reset token. */
function forgotApiVerifiedToken(Student $student): string
{
    forgotApiRequestCode($student->student_number)->assertOk();

    return forgotApiVerify($student->student_number, forgotApiCodeFor($student))
        ->assertOk()
        ->json('reset_token');
}

/** Step 3 — the student sets a new password. */
function forgotApiReset(string $studentNumber, string $token, string $password = FORGOT_API_NEW_PASSWORD, ?string $confirmation = null)
{
    return test()->postJson('/api/auth/forgot-password/reset', [
        'student_number' => $studentNumber,
        'reset_token' => $token,
        'new_password' => $password,
        'new_password_confirmation' => $confirmation ?? $password,
    ]);
}

// ---------------------------------------------------------------------------
// Step 1 — requesting a code
// ---------------------------------------------------------------------------

it('generates a 6-digit code that lives for 5 minutes', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)
        ->assertOk()
        ->assertJsonPath('retry_after', 300);

    expect(PasswordResetCode::count())->toBe(1);

    $row = PasswordResetCode::first();

    expect($row->student_id)->toBe($student->id)
        ->and($row->code)->toMatch('/^\d{6}$/')
        ->and($row->attempts)->toBe(0)
        ->and($row->used_at)->toBeNull()
        ->and($row->expires_at->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp());
});

it('stores the code encrypted rather than as plain text', function () {
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)->assertOk();

    $raw = DB::table('password_reset_codes')->value('code');

    expect($raw)->not->toMatch('/^\d{6}$/')
        ->and(PasswordResetCode::first()->code)->toMatch('/^\d{6}$/');
});

it('answers an unknown student id exactly like a real one, and creates nothing', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    $known = forgotApiRequestCode($student->student_number)->assertOk();
    $unknown = forgotApiRequestCode('1999-9-99999')->assertOk();

    expect($unknown->json())->toEqual($known->json())
        ->and(PasswordResetCode::count())->toBe(1);
});

it('does not issue a second code inside the 5-minute cooldown, but does once it has passed', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)->assertOk()->assertJsonPath('retry_after', 300);

    $this->travel(100)->seconds();
    forgotApiRequestCode($student->student_number)->assertOk()->assertJsonPath('retry_after', 200);
    expect(PasswordResetCode::count())->toBe(1);

    $this->travel(201)->seconds(); // 301s after the first request
    forgotApiRequestCode($student->student_number)->assertOk()->assertJsonPath('retry_after', 300);
    expect(PasswordResetCode::count())->toBe(2);
});

it('applies the same cooldown to an unknown student id so the two cannot be told apart', function () {
    $this->freezeSecond();

    forgotApiRequestCode('1999-9-99999')->assertJsonPath('retry_after', 300);

    $this->travel(100)->seconds();
    forgotApiRequestCode('1999-9-99999')->assertJsonPath('retry_after', 200);
});

it('refuses a new code while one is still unused and unexpired, even if the cooldown cache was lost', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)
        ->assertOk()
        ->assertJsonPath('already_active', false);
    $code = forgotApiCodeFor($student);

    // Simulate the cooldown cache having been lost (a cache clear, a deploy).
    RateLimiter::clear(RequestPasswordResetCode::cooldownKey($student->student_number));
    $this->travel(60)->seconds();

    forgotApiRequestCode($student->student_number)
        ->assertOk()
        ->assertJsonPath('already_active', true)
        ->assertJsonPath('retry_after', 240);

    // Nothing new was issued and the original code still works.
    expect(PasswordResetCode::count())->toBe(1);
    forgotApiVerify($student->student_number, $code)->assertOk();
});

it('reports already_active the same way for a real and an unknown student id', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)->assertJsonPath('already_active', false);
    forgotApiRequestCode('1999-9-99999')->assertJsonPath('already_active', false);

    $this->travel(30)->seconds();

    $known = forgotApiRequestCode($student->student_number)->assertJsonPath('already_active', true);
    $unknown = forgotApiRequestCode('1999-9-99999')->assertJsonPath('already_active', true);

    expect($unknown->json())->toEqual($known->json());
});

it('does not move a code\'s expiry when a wrong guess is recorded', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();

    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);
    $expiresAt = PasswordResetCode::first()->expires_at->getTimestamp();

    $this->travel(30)->seconds();
    forgotApiVerify($student->student_number, forgotApiWrongCode($code))->assertStatus(422);

    $row = PasswordResetCode::first();

    expect($row->attempts)->toBe(1)
        ->and($row->expires_at->getTimestamp())->toBe($expiresAt)
        ->and($expiresAt)->toBe(now()->subSeconds(30)->addMinutes(5)->getTimestamp());
});

it('throttles repeated code requests for the same ip and student id', function () {
    $student = forgotApiStudent();

    for ($i = 0; $i < 5; $i++) {
        forgotApiRequestCode($student->student_number)->assertOk();
    }

    forgotApiRequestCode($student->student_number)->assertStatus(429);
});

it('requires a student number to request a code', function () {
    $this->postJson('/api/auth/forgot-password', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('student_number');
});

// ---------------------------------------------------------------------------
// Step 2 — verifying the code
// ---------------------------------------------------------------------------

it('accepts the right code, spends it, and hands back a reset token', function () {
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();

    $response = forgotApiVerify($student->student_number, forgotApiCodeFor($student))
        ->assertOk()
        ->assertJsonStructure(['reset_token', 'expires_in'])
        ->assertJsonPath('expires_in', 300);

    $token = $response->json('reset_token');
    $row = PasswordResetCode::first();

    expect($token)->toHaveLength(64)
        ->and($row->used_at)->not->toBeNull()
        // Only a hash of the token is kept.
        ->and($row->reset_token_hash)->toBe(hash('sha256', $token))
        ->and($row->reset_token_hash)->not->toBe($token);
});

it('never accepts the same code twice', function () {
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);

    forgotApiVerify($student->student_number, $code)->assertOk();
    forgotApiVerify($student->student_number, $code)->assertStatus(422);
});

it('rejects a wrong code and counts down the attempts left', function () {
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $wrong = forgotApiWrongCode(forgotApiCodeFor($student));

    forgotApiVerify($student->student_number, $wrong)
        ->assertStatus(422)
        ->assertJsonPath('attempts_remaining', 4);

    forgotApiVerify($student->student_number, $wrong)
        ->assertStatus(422)
        ->assertJsonPath('attempts_remaining', 3);

    expect(PasswordResetCode::first()->attempts)->toBe(2)
        ->and(PasswordResetCode::first()->used_at)->toBeNull();
});

it('locks the code after 5 wrong attempts, even against the correct code', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);
    $wrong = forgotApiWrongCode($code);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        forgotApiVerify($student->student_number, $wrong)
            ->assertStatus(422)
            ->assertJsonPath('attempts_remaining', 5 - $attempt);
    }

    // The fifth wrong guess burns the code — answered like a login throttle:
    // 429 + Retry-After, here "until the code would have expired anyway".
    forgotApiVerify($student->student_number, $wrong)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '300')
        ->assertJsonPath('retry_after', 300);

    // Step past the per-minute HTTP throttle so it's the code's own lock
    // that's being tested from here on, not the middleware.
    $this->travel(61)->seconds();

    forgotApiVerify($student->student_number, $code)->assertStatus(429);

    expect(PasswordResetCode::first()->used_at)->toBeNull();
});

it('locks the code after 5 wrong guesses even when every guess comes from a different ip', function () {
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);
    $wrong = forgotApiWrongCode($code);

    // An attacker rotating IPs sails past the per-IP throttle — the limit on
    // the code itself is what stops them.
    for ($i = 1; $i <= 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
            ->postJson('/api/auth/forgot-password/verify', [
                'student_number' => $student->student_number,
                'code' => $wrong,
            ]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
        ->postJson('/api/auth/forgot-password/verify', [
            'student_number' => $student->student_number,
            'code' => $code,
        ])
        ->assertStatus(429);

    expect(PasswordResetCode::first()->used_at)->toBeNull();
});

it('rejects a code once its 5 minutes are up', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);

    $this->travel(5)->minutes();

    forgotApiVerify($student->student_number, $code)
        ->assertStatus(422)
        ->assertJsonMissingPath('attempts_remaining');
});

it('still accepts a code in its last seconds', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();
    $code = forgotApiCodeFor($student);

    $this->travel(299)->seconds();

    forgotApiVerify($student->student_number, $code)->assertOk();
});

it('answers an unknown student id the same way as a bad code, without revealing anything', function () {
    forgotApiVerify('1999-9-99999', '123456')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Incorrect or expired code.')
        ->assertJsonMissingPath('attempts_remaining');
});

it('rejects a malformed code without spending an attempt', function () {
    $student = forgotApiStudent();
    forgotApiRequestCode($student->student_number)->assertOk();

    forgotApiVerify($student->student_number, 'abc123')->assertStatus(422)->assertJsonValidationErrors('code');
    forgotApiVerify($student->student_number, '12345')->assertStatus(422)->assertJsonValidationErrors('code');

    expect(PasswordResetCode::first()->attempts)->toBe(0);
});

it('throttles repeated verify attempts for the same ip and student id, like login does', function () {
    for ($i = 0; $i < 5; $i++) {
        forgotApiVerify('1999-9-99999', '123456')->assertStatus(422);
    }

    forgotApiVerify('1999-9-99999', '123456')->assertStatus(429);
});

// ---------------------------------------------------------------------------
// Step 3 — setting the new password
// ---------------------------------------------------------------------------

it('resets the password, clears the first-login gate and signs the student out everywhere', function () {
    $student = forgotApiStudent(['must_change_password' => true]);
    $student->createToken('api');
    $token = forgotApiVerifiedToken($student);

    forgotApiReset($student->student_number, $token)->assertOk();

    $student->refresh();

    expect(Hash::check(FORGOT_API_NEW_PASSWORD, $student->password))->toBeTrue()
        ->and($student->must_change_password)->toBeFalse()
        ->and($student->tokens()->count())->toBe(0);

    $this->postJson('/api/auth/login', ['username' => $student->username, 'password' => FORGOT_API_NEW_PASSWORD])
        ->assertOk();
    $this->postJson('/api/auth/login', ['username' => $student->username, 'password' => FORGOT_API_OLD_PASSWORD])
        ->assertStatus(401);
});

it('lets a reset token be used only once', function () {
    $student = forgotApiStudent();
    $token = forgotApiVerifiedToken($student);

    forgotApiReset($student->student_number, $token)->assertOk();
    forgotApiReset($student->student_number, $token, 'Another-Password2@')->assertStatus(410);

    expect(Hash::check(FORGOT_API_NEW_PASSWORD, $student->fresh()->password))->toBeTrue();
});

it('does not spend the reset token on a password that fails validation', function () {
    $student = forgotApiStudent();
    $token = forgotApiVerifiedToken($student);

    // Long enough, but no uppercase, number or symbol.
    forgotApiReset($student->student_number, $token, 'lowercaseonly')
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_password');

    forgotApiReset($student->student_number, $token, FORGOT_API_NEW_PASSWORD, 'typo-password')
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_password');

    expect(Hash::check(FORGOT_API_OLD_PASSWORD, $student->fresh()->password))->toBeTrue();

    // The same token still works once the password is acceptable.
    forgotApiReset($student->student_number, $token)->assertOk();
});

it('rejects a reset with a token that was never issued', function () {
    $student = forgotApiStudent();
    forgotApiVerifiedToken($student);

    forgotApiReset($student->student_number, str_repeat('a', 64))->assertStatus(410);

    expect(Hash::check(FORGOT_API_OLD_PASSWORD, $student->fresh()->password))->toBeTrue();
});

it('rejects a reset token presented for a different student', function () {
    $victim = forgotApiStudent();
    $attacker = forgotApiStudent(['student_number' => '2023-1-00002', 'username' => '2023-1-00002']);
    $attackersToken = forgotApiVerifiedToken($attacker);

    forgotApiReset($victim->student_number, $attackersToken)->assertStatus(410);

    expect(Hash::check(FORGOT_API_OLD_PASSWORD, $victim->fresh()->password))->toBeTrue();
});

it('lets the reset session run 5 minutes from verification, and no longer', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    $token = forgotApiVerifiedToken($student);

    $this->travel(5)->minutes();

    forgotApiReset($student->student_number, $token)->assertStatus(410);

    expect(Hash::check(FORGOT_API_OLD_PASSWORD, $student->fresh()->password))->toBeTrue();
});

it('still accepts the reset in the last seconds of the session', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    $token = forgotApiVerifiedToken($student);

    $this->travel(299)->seconds();

    forgotApiReset($student->student_number, $token)->assertOk();
});

it('revokes a half-finished reset session when a newer code is requested', function () {
    $this->freezeSecond();
    $student = forgotApiStudent();
    $token = forgotApiVerifiedToken($student);

    RateLimiter::clear(RequestPasswordResetCode::cooldownKey($student->student_number));
    forgotApiRequestCode($student->student_number)->assertOk();

    forgotApiReset($student->student_number, $token)->assertStatus(410);
});
