<?php

use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Application\Actions\Sessions\DeleteWindow;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\WindowHasStartedSessionException;
use App\Domain\Exceptions\WindowNotFoundOnDayException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dwDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function dwAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => dwDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function dwStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => dwDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function dwEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => dwAdmin()->id], $overrides));
}

function dwDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function dwSession(EventDay $day, array $overrides = []): AttendanceSession
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

function dwExclusion(EventModel $event, Student $student, Student $creator, array $overrides = []): Exclusion
{
    return Exclusion::create(array_merge([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Window,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $creator->id,
    ], $overrides));
}

it('deletes every still-scheduled session of a window with two checks', function () {
    $event = dwEvent();
    $day = dwDay($event);
    $timeIn = dwSession($day, ['check_type' => CheckType::TimeIn]);
    $timeOut = dwSession($day, ['check_type' => CheckType::TimeOut, 'start_time' => '16:00:00', 'end_time' => '17:00:00']);

    $summary = (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin());

    expect($summary['sessions_deleted'])->toBe(2)
        ->and($summary['window_type'])->toBe(WindowType::Morning->value)
        ->and($summary['event_day_id'])->toBe($day->id);
    expect(AttendanceSession::find($timeIn->id))->toBeNull()
        ->and(AttendanceSession::find($timeOut->id))->toBeNull();
});

it('deletes a window that only ever had a single check configured', function () {
    $event = dwEvent();
    $day = dwDay($event);
    $onlyCheck = dwSession($day, ['check_type' => CheckType::TimeIn]);

    $summary = (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin());

    expect($summary['sessions_deleted'])->toBe(1);
    expect(AttendanceSession::find($onlyCheck->id))->toBeNull();
});

it('always revokes an active window-scope exclusion for the deleted window, with no surviving-sibling exception', function () {
    $admin = dwAdmin();
    $student = dwStudent();
    $event = dwEvent();
    $day = dwDay($event);
    dwSession($day, ['check_type' => CheckType::TimeIn]);
    dwSession($day, ['check_type' => CheckType::TimeOut, 'start_time' => '16:00:00', 'end_time' => '17:00:00']);
    $exclusion = dwExclusion($event, $student, $admin, [
        'event_day_id' => $day->id, 'window_type' => WindowType::Morning,
    ]);

    $summary = (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, $admin);

    expect($summary['exclusions_removed'])->toBe(1);
    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Removed)
        ->and($exclusion->removed_by)->toBe($admin->id);
});

it('does not touch an exclusion for a different window_type on the same day', function () {
    $admin = dwAdmin();
    $student = dwStudent();
    $event = dwEvent();
    $day = dwDay($event);
    dwSession($day, ['window_type' => WindowType::Morning, 'check_type' => CheckType::TimeIn]);
    $afternoonExclusion = dwExclusion($event, $student, $admin, [
        'event_day_id' => $day->id, 'window_type' => WindowType::Afternoon,
    ]);

    (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, $admin);

    expect($afternoonExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('does not touch a day-scope or event-scope exclusion when deleting a window', function () {
    $admin = dwAdmin();
    $student = dwStudent();
    $event = dwEvent();
    $day = dwDay($event);
    dwSession($day, ['window_type' => WindowType::Morning, 'check_type' => CheckType::TimeIn]);
    $dayExclusion = dwExclusion($event, $student, $admin, [
        'scope' => ExclusionScope::Day, 'event_day_id' => $day->id, 'window_type' => null,
    ]);
    $eventExclusion = dwExclusion($event, dwStudent('2023105414'), $admin, [
        'scope' => ExclusionScope::Event, 'event_day_id' => null, 'window_type' => null,
    ]);

    (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, $admin);

    expect($dayExclusion->refresh()->status)->toBe(ExclusionStatus::Active)
        ->and($eventExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('throws WindowNotFoundOnDayException when the window has no sessions at all', function () {
    $event = dwEvent();
    $day = dwDay($event);

    (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin());
})->throws(WindowNotFoundOnDayException::class);

it('throws WindowHasStartedSessionException when even one check in the window has started', function () {
    $event = dwEvent();
    $day = dwDay($event);
    dwSession($day, ['check_type' => CheckType::TimeIn, 'status' => SessionStatus::Ongoing]);
    dwSession($day, ['check_type' => CheckType::TimeOut, 'start_time' => '16:00:00', 'end_time' => '17:00:00']);

    (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin());
})->throws(WindowHasStartedSessionException::class);

it('throws WindowHasStartedSessionException when even one check in the window has ended, and deletes nothing', function () {
    $event = dwEvent();
    $day = dwDay($event);
    $ended = dwSession($day, ['check_type' => CheckType::TimeIn, 'status' => SessionStatus::Ended, 'ended_at' => now()]);
    $scheduled = dwSession($day, ['check_type' => CheckType::TimeOut, 'start_time' => '16:00:00', 'end_time' => '17:00:00']);

    expect(fn () => (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin()))
        ->toThrow(WindowHasStartedSessionException::class);

    expect(AttendanceSession::find($ended->id))->not->toBeNull()
        ->and(AttendanceSession::find($scheduled->id))->not->toBeNull();
});

it('throws when the parent event has already ended', function () {
    $event = dwEvent(['status' => EventStatus::Ended]);
    $day = dwDay($event);
    dwSession($day, ['check_type' => CheckType::TimeIn]);

    (new DeleteWindow(new RemoveExclusion))($day, WindowType::Morning, dwAdmin());
})->throws(EventAlreadyEndedException::class);
