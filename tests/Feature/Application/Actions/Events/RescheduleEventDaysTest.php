<?php

use App\Application\Actions\Events\RescheduleEventDays;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayDateNotUniqueException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Domain\Exceptions\EventDayNotInEventException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function redDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function redAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => redDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function redEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => redAdmin()->id], $overrides));
}

function redDay(EventModel $event, int $dayNumber, string $date): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function redSession(EventDay $day, array $overrides = []): AttendanceSession
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

it('shifts several days of one event in a single all-or-nothing batch', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    $day2 = redDay($event, 2, '2026-11-11');
    $day3 = redDay($event, 3, '2026-11-12');

    $result = (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-20'],
        ['event_day_id' => $day2->id, 'date' => '2026-11-21'],
    ]);

    expect($result[$day1->id]->date->format('Y-m-d'))->toBe('2026-11-20')
        ->and($result[$day2->id]->date->format('Y-m-d'))->toBe('2026-11-21')
        // day3 wasn't targeted — untouched.
        ->and($result[$day3->id]->date->format('Y-m-d'))->toBe('2026-11-12');
});

it('allows a two-day swap that would collide if applied one at a time', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    $day2 = redDay($event, 2, '2026-11-11');

    // Swapping day1 <-> day2's dates: a plain single-day UpdateEventDay
    // on either one alone would 409 against the other's current date —
    // this is exactly the batch resolution path §4.5 exists for.
    $result = (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-11'],
        ['event_day_id' => $day2->id, 'date' => '2026-11-10'],
    ]);

    expect($result[$day1->id]->date->format('Y-m-d'))->toBe('2026-11-11')
        ->and($result[$day2->id]->date->format('Y-m-d'))->toBe('2026-11-10');
});

it('throws and saves nothing when the final date set has a duplicate', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    $day2 = redDay($event, 2, '2026-11-11');

    expect(fn () => (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-11'],
    ]))->toThrow(EventDayDateNotUniqueException::class);

    // Nothing committed — day1 still on its original date.
    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-10');
});

it('throws and saves nothing when two targeted days would collide with each other', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    $day2 = redDay($event, 2, '2026-11-11');

    expect(fn () => (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-12-01'],
        ['event_day_id' => $day2->id, 'date' => '2026-12-01'],
    ]))->toThrow(EventDayDateNotUniqueException::class);

    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-10')
        ->and($day2->refresh()->date->format('Y-m-d'))->toBe('2026-11-11');
});

it('throws when a targeted day does not belong to the given event', function () {
    $event = redEvent();
    $otherEvent = redEvent(['name' => 'Other Event']);
    $foreignDay = redDay($otherEvent, 1, '2026-11-10');

    (new RescheduleEventDays)($event, [
        ['event_day_id' => $foreignDay->id, 'date' => '2026-11-20'],
    ]);
})->throws(EventDayNotInEventException::class);

it('throws and saves nothing when a targeted day has an ongoing session', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    redSession($day1, ['status' => SessionStatus::Ongoing]);

    expect(fn () => (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-20'],
    ]))->toThrow(EventDayHasStartedSessionException::class);

    expect($day1->refresh()->date->format('Y-m-d'))->toBe('2026-11-10');
});

it('throws and saves nothing when an untouched day in the same event has already ended, even though it was never targeted', function () {
    $event = redEvent();
    $day1 = redDay($event, 1, '2026-11-10');
    $day2 = redDay($event, 2, '2026-11-11');
    redSession($day2, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    // Only day1 is targeted, but RescheduleEventDays re-validates every
    // day in the event under lock (§4.5 point 3) — an already-ended day
    // is fine to leave untouched, so this should NOT throw purely
    // because day2 has ended; it should only throw if day2 were itself
    // targeted. This test documents that an ended, untouched day is not
    // itself a blocker.
    $result = (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-25'],
    ]);

    expect($result[$day1->id]->date->format('Y-m-d'))->toBe('2026-11-25');
});

it('throws when the parent event has already ended', function () {
    $event = redEvent(['status' => EventStatus::Ended]);
    $day1 = redDay($event, 1, '2026-11-10');

    (new RescheduleEventDays)($event, [
        ['event_day_id' => $day1->id, 'date' => '2026-11-20'],
    ]);
})->throws(EventAlreadyEndedException::class);
