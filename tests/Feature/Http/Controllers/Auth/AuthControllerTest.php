<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function authTestDepartment(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function authTestStudent(array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => authTestDepartment()->id,
        'username' => 'jcruz',
        'password' => 'correct-password',
        'role' => Role::Student,
        'must_change_password' => false,
        // Clears the photo.uploaded gate — see the same note in
        // ReverseScanControllerTest. These tests exercise the
        // password-changed gate and role authorization, not the photo
        // gate (covered separately by EnsurePhotoHasBeenUploadedTest).
        'photo_path' => 'students/placeholder.jpg',
    ], $overrides));
}

// A session fixture only needed by the tests that check the password-changed
// gate on a real protected endpoint (not auth itself).
function authTestSession(): AttendanceSession
{
    $creator = Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => authTestDepartment()->id,
        'username' => 'csgadmin', 'password' => 'password',
        'role' => Role::CsgAdmin,
        'must_change_password' => false,
    ]);

    $event = EventModel::create(['name' => 'Test Event', 'created_by' => $creator->id]);

    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ongoing,
    ]);
}

it('logs in with correct credentials and returns a token', function () {
    authTestStudent();

    $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'correct-password',
    ])
        ->assertStatus(200)
        ->assertJsonStructure(['token', 'must_change_password', 'student'])
        ->assertJsonPath('must_change_password', false);
});

it('includes the student\'s department on login, for the ID/QR screen', function () {
    authTestStudent();

    $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'correct-password',
    ])
        ->assertStatus(200)
        ->assertJsonPath('student.department.code', 'CCS');
});

it('includes the student\'s department on /auth/me', function () {
    $student = authTestStudent();

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/auth/me')
        ->assertStatus(200)
        ->assertJsonPath('department.code', 'CCS');
});

it('rejects login with a wrong password', function () {
    authTestStudent();

    $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'wrong-password',
    ])->assertStatus(401);
});

it('rejects login with an unknown username', function () {
    $this->postJson('/api/auth/login', [
        'username' => 'nobody',
        'password' => 'whatever',
    ])->assertStatus(401);
});

it('validates that username and password are present', function () {
    $this->postJson('/api/auth/login', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['username', 'password']);
});

it('flags must_change_password on a first-time login', function () {
    authTestStudent(['must_change_password' => true]);

    $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'correct-password',
    ])
        ->assertStatus(200)
        ->assertJsonPath('must_change_password', true);
});

it('blocks a pending-password-change account from a gated endpoint', function () {
    $student = authTestStudent(['must_change_password' => true]);
    $session = authTestSession();

    $this->actingAs($student, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/end")
        ->assertStatus(403);
});

it('allows a pending-password-change account to call change-password itself', function () {
    $student = authTestStudent(['must_change_password' => true]);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/auth/change-password', [
            'current_password' => 'correct-password',
            'new_password' => 'Brand-New-Password1!',
            'new_password_confirmation' => 'Brand-New-Password1!',
        ])
        ->assertStatus(200);

    expect($student->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('Brand-New-Password1!', $student->fresh()->password))->toBeTrue();
});

it('rejects change-password with the wrong current password', function () {
    $student = authTestStudent();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/auth/change-password', [
            'current_password' => 'not-the-real-password',
            'new_password' => 'Brand-New-Password1!',
            'new_password_confirmation' => 'Brand-New-Password1!',
        ])
        ->assertStatus(422);

    expect(Hash::check('correct-password', $student->fresh()->password))->toBeTrue();
});

it('rejects change-password when new password does not match confirmation', function () {
    $student = authTestStudent();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/auth/change-password', [
            'current_password' => 'correct-password',
            'new_password' => 'Brand-New-Password1!',
            'new_password_confirmation' => 'typo-password',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_password');
});

it('rejects change-password when the new password fails the complexity requirements', function () {
    $student = authTestStudent();

    // Long enough (>=8) but missing uppercase, a number, and a symbol —
    // min:8 alone would have let this through before the Password rule
    // (mixedCase + numbers + symbols) was added.
    $this->actingAs($student, 'sanctum')
        ->postJson('/api/auth/change-password', [
            'current_password' => 'correct-password',
            'new_password' => 'lowercaseonly',
            'new_password_confirmation' => 'lowercaseonly',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('new_password');

    expect(Hash::check('correct-password', $student->fresh()->password))->toBeTrue();
});

it('clears the gate after a successful password change, unblocking gated endpoints', function () {
    $student = authTestStudent(['must_change_password' => true]);
    $session = authTestSession();

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/auth/change-password', [
            'current_password' => 'correct-password',
            'new_password' => 'Brand-New-Password1!',
            'new_password_confirmation' => 'Brand-New-Password1!',
        ])
        ->assertStatus(200);

    // Sanctum resolves the user fresh per request via the token, but
    // actingAs() pins the in-memory instance — refetch to get the updated flag.
    $this->actingAs($student->fresh(), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/end")
        ->assertStatus(200);
});

it('logs out and revokes the current token', function () {
    authTestStudent();

    $login = $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'correct-password',
    ])->assertStatus(200);

    $token = $login->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout')
        ->assertStatus(200);

    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/auth/me')
        ->assertStatus(401);
});

it('throttles repeated login attempts for the same ip and username', function () {
    // Login previously had no throttle at all — unlimited password
    // guesses against any username. The 'login' limiter in
    // AppServiceProvider allows 5/minute keyed on ip+username; the 6th
    // attempt in the same minute should be rejected before it ever
    // reaches AuthController, regardless of whether the password is right.
    authTestStudent();

    $attempt = fn () => $this->postJson('/api/auth/login', [
        'username' => 'jcruz',
        'password' => 'wrong-password',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $attempt()->assertStatus(401);
    }

    $attempt()->assertStatus(429);
});
