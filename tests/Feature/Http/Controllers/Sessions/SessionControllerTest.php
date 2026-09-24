<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// event-day-window-edit-delete-plan.md §6: PATCH /sessions/{session} and
// DELETE /sessions/{session} — controller-level coverage for
// UpdateSession/DeleteSession.

function uscaDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function uscaAdmin(string $studentNumber = '2020000001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => uscaDept()->id,
            'username' => 'csgadmin'.$studentNumber, 'password' => 'password',
            'role' => 'csg_admin', 'must_change_password' => false,
        ],
    );
}

function uscaOfficer(string $studentNumber = '2020200001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Officer', 'first_name' => 'Test',
            'department_id' => uscaDept()->id,
            'username' => 'officer'.$studentNumber, 'password' => 'password',
            'role' => 'officer', 'must_change_password' => false,
        ],
    );
}

function uscaStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => uscaDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function uscaEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => uscaAdmin()->id], $overrides));
}

function uscaDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function uscaSession(EventDay $day, array $overrides = []): AttendanceSession
{
    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Scheduled,
    ], $overrides));
}

// --- UpdateSession (PATCH) ---

it('rejects an unauthenticated session update', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5])
        ->assertStatus(401);
});

it('rejects a session update from a non-admin role', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaOfficer(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5])
        ->assertStatus(403);
});

it('lets a csg admin update a scheduled session', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5, 'start_time' => '06:30'])
        ->assertStatus(200)
        ->assertJsonPath('grace_minutes', 5);
});

it('allows a partial update of a single field without tripping self-uniqueness (Bug #13)', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 10])
        ->assertStatus(200)
        ->assertJsonPath('grace_minutes', 10)
        ->assertJsonPath('window_type', 'morning');
});

it('returns 409 updating a session that is already ongoing', function () {
    $session = uscaSession(uscaDay(uscaEvent()), ['status' => SessionStatus::Ongoing]);

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5])
        ->assertStatus(409);
});

it('returns 409 updating a session that has already ended', function () {
    $session = uscaSession(uscaDay(uscaEvent()), ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5])
        ->assertStatus(409);
});

it('returns 409 updating a session whose parent event has already ended', function () {
    $event = uscaEvent(['status' => EventStatus::Ended]);
    $session = uscaSession(uscaDay($event));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['grace_minutes' => 5])
        ->assertStatus(409);
});

it('returns 404 updating a nonexistent session', function () {
    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson('/api/sessions/999999', ['grace_minutes' => 5])
        ->assertStatus(404);
});

it('rejects a session update where end_time is not after start_time', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$session->id}", ['start_time' => '09:00', 'end_time' => '08:00'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_time');
});

it('rejects a session update colliding with a sibling check', function () {
    $day = uscaDay(uscaEvent());
    uscaSession($day, ['check_type' => CheckType::TimeOut]);
    $timeIn = uscaSession($day, ['check_type' => CheckType::TimeIn]);

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->patchJson("/api/sessions/{$timeIn->id}", ['check_type' => 'time_out'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('window_type');
});

// --- DeleteSession (DELETE) ---

it('rejects an unauthenticated session deletion', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(401);
});

it('rejects a session deletion from a non-admin role', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaOfficer(), 'sanctum')
        ->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(403);
});

it('lets a csg admin delete a scheduled session with no sibling check', function () {
    $session = uscaSession(uscaDay(uscaEvent()));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(200)
        ->assertJsonPath('session_id', $session->id);

    expect(AttendanceSession::find($session->id))->toBeNull();
});

it('returns 409 deleting a session that is already ongoing', function () {
    $session = uscaSession(uscaDay(uscaEvent()), ['status' => SessionStatus::Ongoing]);

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(409);

    expect(AttendanceSession::find($session->id))->not->toBeNull();
});

it('returns 409 deleting a session whose parent event has already ended', function () {
    $event = uscaEvent(['status' => EventStatus::Ended]);
    $session = uscaSession(uscaDay($event));

    $this->actingAs(uscaAdmin(), 'sanctum')
        ->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(409);
});

it('returns 404 deleting a nonexistent session', function () {
    $this->actingAs(uscaAdmin(), 'sanctum')
        ->deleteJson('/api/sessions/999999')
        ->assertStatus(404);
});

it('cascades window-scope exclusion soft-removal when deleting the last check', function () {
    $admin = uscaAdmin();
    $student = uscaStudent();
    $event = uscaEvent();
    $day = uscaDay($event);
    $session = uscaSession($day);
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'scope' => ExclusionScope::Window,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/sessions/{$session->id}")
        ->assertStatus(200)
        ->assertJsonPath('exclusions_removed', 1);

    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Removed);
});

it('does not cascade exclusion removal while a sibling check survives', function () {
    $admin = uscaAdmin();
    $student = uscaStudent();
    $event = uscaEvent();
    $day = uscaDay($event);
    $timeIn = uscaSession($day, ['check_type' => CheckType::TimeIn]);
    uscaSession($day, ['check_type' => CheckType::TimeOut]);
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'scope' => ExclusionScope::Window,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/sessions/{$timeIn->id}")
        ->assertStatus(200)
        ->assertJsonPath('exclusions_removed', 0);

    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});
