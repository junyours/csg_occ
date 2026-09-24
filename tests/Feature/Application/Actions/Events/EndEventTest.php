<?php

use App\Application\Actions\Events\EndEvent;
use App\Application\Actions\Sessions\EndSession;
use App\Application\Actions\Sessions\StartSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function endEventDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function endEventCreator(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => endEventDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );
}

function endEventWithDay(): EventModel
{
    $event = EventModel::create(['name' => 'Test Event', 'created_by' => endEventCreator()->id]);

    EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return $event;
}

function endEventSession(EventModel $event, array $overrides = []): AttendanceSession
{
    $day = $event->days()->first();

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

it('creates every new event as ongoing', function () {
    $event = endEventWithDay();

    expect($event->fresh()->status)->toBe(EventStatus::Ongoing);
});

it('ends the event without touching a session that was never started', function () {
    $event = endEventWithDay();
    $session = endEventSession($event); // stays Scheduled

    $result = (new EndEvent(new EndSession))($event);

    expect($event->fresh()->status)->toBe(EventStatus::Ended)
        ->and($session->fresh()->status)->toBe(SessionStatus::Scheduled) // untouched
        ->and($result['sessions_ended'])->toBe(0);
});

it('force-ends every ongoing session when the event ends', function () {
    $event = endEventWithDay();
    // StartSession enforces "only one ongoing session per event" as a
    // real invariant (see its own docblock), so two sessions can never
    // both reach Ongoing through that action at once. EndEvent's cascade
    // still needs to be robust against multiple ongoing sessions under
    // one event regardless of how that state was reached, so this test
    // sets it up directly rather than through StartSession.
    $morning = endEventSession($event, ['window_type' => WindowType::Morning, 'status' => SessionStatus::Ongoing]);
    $afternoon = endEventSession($event, ['window_type' => WindowType::Afternoon, 'status' => SessionStatus::Ongoing]);

    $result = (new EndEvent(new EndSession))($event);

    expect($event->fresh()->status)->toBe(EventStatus::Ended)
        ->and($morning->fresh()->status)->toBe(SessionStatus::Ended)
        ->and($afternoon->fresh()->status)->toBe(SessionStatus::Ended)
        ->and($result['sessions_ended'])->toBe(2);
});

it('does not end an already-ended session a second time via the cascade', function () {
    $event = endEventWithDay();
    $session = endEventSession($event);

    (new StartSession)($session);
    (new EndSession)($session); // ended individually beforehand

    $result = (new EndEvent(new EndSession))($event);

    expect($result['sessions_ended'])->toBe(0); // wasn't ongoing anymore, so untouched by the cascade
});

it('throws when ending an event that has already been ended', function () {
    $event = endEventWithDay();

    (new EndEvent(new EndSession))($event);

    (new EndEvent(new EndSession))($event);
})->throws(EventAlreadyEndedException::class);

it('refuses to start a session whose event has already ended', function () {
    $event = endEventWithDay();
    $session = endEventSession($event);

    (new EndEvent(new EndSession))($event);

    (new StartSession)($session);
})->throws(EventAlreadyEndedException::class);
