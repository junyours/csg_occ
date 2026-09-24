<?php

use App\Application\Actions\Sessions\StartSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventHasOngoingSessionException;
use App\Domain\Exceptions\SessionNotScheduledException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function startSessionDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function startSessionAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => startSessionDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );
}

function startSessionEvent(): EventModel
{
    return EventModel::create(['name' => 'Test Event', 'created_by' => startSessionAdmin()->id]);
}

function startSessionFixture(array $overrides = [], ?EventModel $event = null): AttendanceSession
{
    $dayNumber = $overrides['day_number'] ?? 1;
    unset($overrides['day_number']);

    // firstOrCreate, not create: tests that put a second (different
    // window/check) session on the same day_number of the same event
    // must share one EventDay row — event_days has a unique constraint
    // on (event_id, day_number).
    $day = EventDay::firstOrCreate(
        ['event_id' => ($event ?? startSessionEvent())->id, 'day_number' => $dayNumber],
        ['date' => '2026-11-10'],
    );

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

it('moves a scheduled session to ongoing', function () {
    $session = startSessionFixture();

    $started = (new StartSession)($session);

    expect($started->status)->toBe(SessionStatus::Ongoing);
    $session->refresh();
    expect($session->status)->toBe(SessionStatus::Ongoing);
});

it('throws when starting a session that is already ongoing', function () {
    $session = startSessionFixture(['status' => SessionStatus::Ongoing]);

    (new StartSession)($session);
})->throws(SessionNotScheduledException::class);

it('throws when starting a session that has already ended', function () {
    $session = startSessionFixture(['status' => SessionStatus::Ended]);

    (new StartSession)($session);
})->throws(SessionNotScheduledException::class);

it('throws when another session under the same event is already ongoing', function () {
    $event = startSessionEvent();
    $ongoing = startSessionFixture(['status' => SessionStatus::Ongoing, 'day_number' => 1], $event);
    $scheduled = startSessionFixture(['day_number' => 2], $event);

    (new StartSession)($scheduled);
})->throws(EventHasOngoingSessionException::class);

it('allows starting a second session in the same event once the first one has ended', function () {
    $event = startSessionEvent();
    $ended = startSessionFixture(['status' => SessionStatus::Ended, 'day_number' => 1], $event);
    $scheduled = startSessionFixture(['day_number' => 2], $event);

    $started = (new StartSession)($scheduled);

    expect($started->status)->toBe(SessionStatus::Ongoing);
});

it('allows two different events to each have their own ongoing session at the same time', function () {
    $eventOne = startSessionEvent();
    $eventTwo = EventModel::create(['name' => 'Second Event', 'created_by' => startSessionAdmin()->id]);

    $ongoingInEventOne = startSessionFixture(['status' => SessionStatus::Ongoing], $eventOne);
    $scheduledInEventTwo = startSessionFixture([], $eventTwo);

    $started = (new StartSession)($scheduledInEventTwo);

    expect($started->status)->toBe(SessionStatus::Ongoing);
    $ongoingInEventOne->refresh();
    expect($ongoingInEventOne->status)->toBe(SessionStatus::Ongoing);
});

it('lets a second, different window/check session in the same event start once no session is ongoing', function () {
    $event = startSessionEvent();
    $timeIn = startSessionFixture(['status' => SessionStatus::Ended, 'day_number' => 1], $event);
    $timeOut = startSessionFixture([
        'day_number' => 1,
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ], $event);

    $started = (new StartSession)($timeOut);

    expect($started->status)->toBe(SessionStatus::Ongoing);
});
