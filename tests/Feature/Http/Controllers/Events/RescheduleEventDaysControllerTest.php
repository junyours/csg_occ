<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// event-day-window-edit-delete-plan.md §6: PATCH /events/{event}/reschedule —
// controller-level coverage for RescheduleEventDays.

function rdcoDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function rdcoAdmin(string $studentNumber = '2020000001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => rdcoDept()->id,
            'username' => 'csgadmin'.$studentNumber, 'password' => 'password',
            'role' => 'csg_admin', 'must_change_password' => false,
        ],
    );
}

function rdcoOfficer(string $studentNumber = '2020200001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Officer', 'first_name' => 'Test',
            'department_id' => rdcoDept()->id,
            'username' => 'officer'.$studentNumber, 'password' => 'password',
            'role' => 'officer', 'must_change_password' => false,
        ],
    );
}

function rdcoEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => rdcoAdmin()->id], $overrides));
}

function rdcoDay(EventModel $event, int $dayNumber, string $date): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function rdcoSession(EventDay $day, array $overrides = []): AttendanceSession
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

it('rejects an unauthenticated reschedule request', function () {
    $event = rdcoEvent();
    $day = rdcoDay($event, 1, '2026-11-10');

    $this->patchJson("/api/events/{$event->id}/reschedule", [
        'targets' => [['event_day_id' => $day->id, 'date' => '2026-11-15']],
    ])->assertStatus(401);
});

it('rejects reschedule from a non-admin role', function () {
    $event = rdcoEvent();
    $day = rdcoDay($event, 1, '2026-11-10');

    $this->actingAs(rdcoOfficer(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [['event_day_id' => $day->id, 'date' => '2026-11-15']],
        ])
        ->assertStatus(403);
});

it('lets a csg admin shift several days in one all-or-nothing batch', function () {
    $event = rdcoEvent();
    $day1 = rdcoDay($event, 1, '2026-11-10');
    $day2 = rdcoDay($event, 2, '2026-11-11');

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [
                ['event_day_id' => $day1->id, 'date' => '2026-11-20'],
                ['event_day_id' => $day2->id, 'date' => '2026-11-21'],
            ],
        ])
        ->assertStatus(200);

    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-20')
        ->and($day2->refresh()->date->format('Y-m-d'))->toBe('2026-11-21');
});

it('allows a two-day swap that would collide if applied one at a time', function () {
    $event = rdcoEvent();
    $day1 = rdcoDay($event, 1, '2026-11-10');
    $day2 = rdcoDay($event, 2, '2026-11-11');

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [
                ['event_day_id' => $day1->id, 'date' => '2026-11-11'],
                ['event_day_id' => $day2->id, 'date' => '2026-11-10'],
            ],
        ])
        ->assertStatus(200);

    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-11')
        ->and($day2->refresh()->date->format('Y-m-d'))->toBe('2026-11-10');
});

it('returns 409 when the final date set has a duplicate', function () {
    $event = rdcoEvent();
    $day1 = rdcoDay($event, 1, '2026-11-10');
    $day2 = rdcoDay($event, 2, '2026-11-11');

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [['event_day_id' => $day1->id, 'date' => '2026-11-11']],
        ])
        ->assertStatus(409);

    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-10')
        ->and($day2->refresh()->date->format('Y-m-d'))->toBe('2026-11-11');
});

it('returns 409 when a targeted day has an ongoing session', function () {
    $event = rdcoEvent();
    $day = rdcoDay($event, 1, '2026-11-10');
    rdcoSession($day, ['status' => SessionStatus::Ongoing]);

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [['event_day_id' => $day->id, 'date' => '2026-11-20']],
        ])
        ->assertStatus(409);
});

it('returns 409 rescheduling an event that has already ended', function () {
    $event = rdcoEvent(['status' => EventStatus::Ended]);
    $day = rdcoDay($event, 1, '2026-11-10');

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [['event_day_id' => $day->id, 'date' => '2026-11-20']],
        ])
        ->assertStatus(409);
});

it('returns 404 rescheduling a nonexistent event', function () {
    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson('/api/events/999999/reschedule', [
            'targets' => [['event_day_id' => 1, 'date' => '2026-11-20']],
        ])
        ->assertStatus(404);
});

it('rejects a target day that does not belong to the given event with a 422', function () {
    $event = rdcoEvent();
    $otherEvent = rdcoEvent(['name' => 'Other Event']);
    $foreignDay = rdcoDay($otherEvent, 1, '2026-11-10');

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", [
            'targets' => [['event_day_id' => $foreignDay->id, 'date' => '2026-11-20']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('targets.0.event_day_id');
});

it('validates that targets is present and non-empty', function () {
    $event = rdcoEvent();

    $this->actingAs(rdcoAdmin(), 'sanctum')
        ->patchJson("/api/events/{$event->id}/reschedule", ['targets' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('targets');
});
