<?php

use App\Application\Actions\Sessions\UpdateSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\SessionAlreadyStartedException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function usDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function usAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => usDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function usEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => usAdmin()->id], $overrides));
}

function usDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function usSession(EventDay $day, array $overrides = []): AttendanceSession
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

it('updates a scheduled session\'s time/grace/penalty fields', function () {
    $event = usEvent();
    $day = usDay($event);
    $session = usSession($day);

    $updated = (new UpdateSession)($session, [
        'start_time' => '08:00',
        'end_time' => '09:00',
        'grace_minutes' => 5,
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 50,
    ]);

    expect($updated->start_time)->toBe('08:00:00')
        ->and($updated->end_time)->toBe('09:00:00')
        ->and($updated->grace_minutes)->toBe(5)
        ->and((float) $updated->penalty_late_amount)->toBe(10.0);
});

it('allows a partial update of a single field', function () {
    $event = usEvent();
    $day = usDay($event);
    $session = usSession($day);

    $updated = (new UpdateSession)($session, ['grace_minutes' => 20]);

    expect($updated->grace_minutes)->toBe(20)
        ->and($updated->start_time)->toBe('07:00:00');
});

it('throws when the session is already ongoing', function () {
    $event = usEvent();
    $day = usDay($event);
    $session = usSession($day, ['status' => SessionStatus::Ongoing]);

    (new UpdateSession)($session, ['grace_minutes' => 20]);
})->throws(SessionAlreadyStartedException::class);

it('throws when the session has already ended', function () {
    $event = usEvent();
    $day = usDay($event);
    $session = usSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    (new UpdateSession)($session, ['grace_minutes' => 20]);
})->throws(SessionAlreadyStartedException::class);

it('throws when the parent event has already ended, even if the session itself never started', function () {
    $event = usEvent(['status' => EventStatus::Ended]);
    $day = usDay($event);
    $session = usSession($day);

    (new UpdateSession)($session, ['grace_minutes' => 20]);
})->throws(EventAlreadyEndedException::class);

it('lets a session keep its own window_type/check_type unchanged without tripping the uniqueness rule at the action level', function () {
    // Bug #13 is really a FormRequest-layer concern (UpdateSessionRequest),
    // but this exercises that the action itself has no opinion on
    // whether window_type/check_type are present in $data at all.
    $event = usEvent();
    $day = usDay($event);
    $session = usSession($day);

    $updated = (new UpdateSession)($session, []);

    expect($updated->window_type)->toBe(WindowType::Morning)
        ->and($updated->check_type)->toBe(CheckType::TimeIn);
});
