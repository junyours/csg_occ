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

// event-day-window-edit-delete-plan.md §6: PATCH /event-days/{eventDay}
// and DELETE /event-days/{eventDay} — controller-level coverage
// (policy 403s, guard 409s, binding 404s) for UpdateEventDay/DeleteEventDay.

function uedcDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function uedcAdmin(string $studentNumber = '2020000001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => uedcDept()->id,
            'username' => 'csgadmin'.$studentNumber, 'password' => 'password',
            'role' => 'csg_admin', 'must_change_password' => false,
        ],
    );
}

function uedcOfficer(string $studentNumber = '2020200001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Officer', 'first_name' => 'Test',
            'department_id' => uedcDept()->id,
            'username' => 'officer'.$studentNumber, 'password' => 'password',
            'role' => 'officer', 'must_change_password' => false,
        ],
    );
}

function uedcStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => uedcDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function uedcEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => uedcAdmin()->id], $overrides));
}

function uedcDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function uedcSession(EventDay $day, array $overrides = []): AttendanceSession
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

// --- UpdateEventDay (PATCH) ---

it('rejects an unauthenticated day update', function () {
    $day = uedcDay(uedcEvent());

    $this->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(401);
});

it('rejects a day update from a non-admin role', function () {
    $day = uedcDay(uedcEvent());

    $this->actingAs(uedcOfficer(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(403);
});

it('lets a csg admin update a day with no started sessions', function () {
    $day = uedcDay(uedcEvent());
    uedcSession($day);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(200)
        ->assertJsonPath('date', '2026-11-15');
});

it('returns 409 updating a day with an ongoing session', function () {
    $day = uedcDay(uedcEvent());
    uedcSession($day, ['status' => SessionStatus::Ongoing]);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(409);
});

it('returns 409 updating a day with an already-ended session', function () {
    $day = uedcDay(uedcEvent());
    uedcSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(409);
});

it('returns 409 updating a day whose parent event has already ended', function () {
    $event = uedcEvent(['status' => EventStatus::Ended]);
    $day = uedcDay($event);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-15'])
        ->assertStatus(409);
});

it('returns 404 updating a nonexistent day', function () {
    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson('/api/event-days/999999', ['date' => '2026-11-15'])
        ->assertStatus(404);
});

it('rejects a day update colliding with another day in the same event', function () {
    $event = uedcEvent();
    uedcDay($event, 1, '2026-11-10');
    $dayToMove = uedcDay($event, 2, '2026-11-11');

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$dayToMove->id}", ['date' => '2026-11-10'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});

it('allows a day update that keeps its own existing date (ignore-self, Bug #13)', function () {
    $day = uedcDay(uedcEvent(), 1, '2026-11-10');

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", ['date' => '2026-11-10'])
        ->assertStatus(200)
        ->assertJsonPath('date', '2026-11-10');
});

it('requires a date on day update', function () {
    $day = uedcDay(uedcEvent());

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->patchJson("/api/event-days/{$day->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});

// --- DeleteEventDay (DELETE) ---

it('rejects an unauthenticated day deletion', function () {
    $day = uedcDay(uedcEvent());

    $this->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(401);
});

it('rejects a day deletion from a non-admin role', function () {
    $day = uedcDay(uedcEvent());

    $this->actingAs(uedcOfficer(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(403);
});

it('lets a csg admin delete a day with only scheduled sessions', function () {
    $day = uedcDay(uedcEvent());
    uedcSession($day);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(200)
        ->assertJsonPath('event_day_id', $day->id);

    expect(EventDay::find($day->id))->toBeNull();
});

it('returns 409 deleting a day with an ongoing session', function () {
    $day = uedcDay(uedcEvent());
    uedcSession($day, ['status' => SessionStatus::Ongoing]);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(409);

    expect(EventDay::find($day->id))->not->toBeNull();
});

it('returns 409 deleting a day whose parent event has already ended', function () {
    $event = uedcEvent(['status' => EventStatus::Ended]);
    $day = uedcDay($event);

    $this->actingAs(uedcAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(409);
});

it('returns 404 deleting a nonexistent day', function () {
    $this->actingAs(uedcAdmin(), 'sanctum')
        ->deleteJson('/api/event-days/999999')
        ->assertStatus(404);
});

it('cascades exclusion soft-removal through the HTTP endpoint', function () {
    $admin = uedcAdmin();
    $student = uedcStudent();
    $event = uedcEvent();
    $day = uedcDay($event);
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'event_day_id' => $day->id,
        'scope' => ExclusionScope::Day,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}")
        ->assertStatus(200)
        ->assertJsonPath('exclusions_removed', 1);

    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Removed);
});
