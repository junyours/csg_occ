<?php

use App\Application\Actions\Events\UpdateEventDay;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function uedDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function uedAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => uedDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function uedEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => uedAdmin()->id], $overrides));
}

function uedDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function uedSession(EventDay $day, array $overrides = []): AttendanceSession
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

it('updates a day with no sessions yet', function () {
    $event = uedEvent();
    $day = uedDay($event);

    $updated = (new UpdateEventDay)($day, ['date' => '2026-11-15']);

    expect($updated->date->format('Y-m-d'))->toBe('2026-11-15');
});

it('updates a day whose sessions are all still scheduled', function () {
    $event = uedEvent();
    $day = uedDay($event);
    uedSession($day);

    $updated = (new UpdateEventDay)($day, ['date' => '2026-11-15']);

    expect($updated->date->format('Y-m-d'))->toBe('2026-11-15');
});

it('throws when a session under the day is ongoing', function () {
    $event = uedEvent();
    $day = uedDay($event);
    uedSession($day, ['status' => SessionStatus::Ongoing]);

    (new UpdateEventDay)($day, ['date' => '2026-11-15']);
})->throws(EventDayHasStartedSessionException::class);

it('throws when a session under the day has already ended', function () {
    $event = uedEvent();
    $day = uedDay($event);
    uedSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    (new UpdateEventDay)($day, ['date' => '2026-11-15']);
})->throws(EventDayHasStartedSessionException::class);

it('throws when the parent event has already ended, even if the day itself never started', function () {
    $event = uedEvent(['status' => EventStatus::Ended]);
    $day = uedDay($event);

    (new UpdateEventDay)($day, ['date' => '2026-11-15']);
})->throws(EventAlreadyEndedException::class);
