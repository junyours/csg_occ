<?php

use App\Application\Actions\Events\DeleteEventDay;
use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dedDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function dedAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => dedDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function dedStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => dedDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function dedEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => dedAdmin()->id], $overrides));
}

function dedDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function dedSession(EventDay $day, array $overrides = []): AttendanceSession
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

function dedExclusion(EventModel $event, Student $student, Student $creator, array $overrides = []): Exclusion
{
    return Exclusion::create(array_merge([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Day,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $creator->id,
    ], $overrides));
}

it('deletes a day with zero sessions', function () {
    $event = dedEvent();
    $day = dedDay($event);

    $summary = (new DeleteEventDay(new RemoveExclusion))($day, dedAdmin());

    expect($summary['event_day_id'])->toBe($day->id)
        ->and($summary['exclusions_removed'])->toBe(0);
    expect(EventDay::find($day->id))->toBeNull();
});

it('deletes a day whose sessions are all still scheduled', function () {
    $event = dedEvent();
    $day = dedDay($event);
    dedSession($day);

    (new DeleteEventDay(new RemoveExclusion))($day, dedAdmin());

    expect(EventDay::find($day->id))->toBeNull();
});

it('cascades soft-removal of an active day-scope exclusion for that day', function () {
    $admin = dedAdmin();
    $student = dedStudent();
    $event = dedEvent();
    $day = dedDay($event);
    $exclusion = dedExclusion($event, $student, $admin, ['scope' => ExclusionScope::Day, 'event_day_id' => $day->id]);

    $summary = (new DeleteEventDay(new RemoveExclusion))($day, $admin);

    expect($summary['exclusions_removed'])->toBe(1);
    $exclusion->refresh();
    expect($exclusion->status)->toBe(ExclusionStatus::Removed)
        ->and($exclusion->removed_by)->toBe($admin->id);
});

it('cascades soft-removal of every active window-scope exclusion for that day', function () {
    $admin = dedAdmin();
    $student = dedStudent();
    $event = dedEvent();
    $day = dedDay($event);
    $morning = dedExclusion($event, $student, $admin, [
        'scope' => ExclusionScope::Window, 'event_day_id' => $day->id, 'window_type' => WindowType::Morning,
    ]);
    $afternoon = dedExclusion($event, dedStudent('2023105414'), $admin, [
        'scope' => ExclusionScope::Window, 'event_day_id' => $day->id, 'window_type' => WindowType::Afternoon,
    ]);

    $summary = (new DeleteEventDay(new RemoveExclusion))($day, $admin);

    expect($summary['exclusions_removed'])->toBe(2);
    expect($morning->refresh()->status)->toBe(ExclusionStatus::Removed)
        ->and($afternoon->refresh()->status)->toBe(ExclusionStatus::Removed);
});

it('does not touch an event-scope exclusion when deleting a day', function () {
    $admin = dedAdmin();
    $student = dedStudent();
    $event = dedEvent();
    $day = dedDay($event);
    $eventExclusion = dedExclusion($event, $student, $admin, ['scope' => ExclusionScope::Event, 'event_day_id' => null]);

    (new DeleteEventDay(new RemoveExclusion))($day, $admin);

    expect($eventExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('does not touch a window-scope exclusion belonging to a different day', function () {
    $admin = dedAdmin();
    $student = dedStudent();
    $event = dedEvent();
    $dayToDelete = dedDay($event, 1, '2026-11-10');
    $otherDay = dedDay($event, 2, '2026-11-11');
    $otherDayExclusion = dedExclusion($event, $student, $admin, [
        'scope' => ExclusionScope::Window, 'event_day_id' => $otherDay->id, 'window_type' => WindowType::Morning,
    ]);

    (new DeleteEventDay(new RemoveExclusion))($dayToDelete, $admin);

    expect($otherDayExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('throws and deletes nothing when a session under the day has already ended', function () {
    $event = dedEvent();
    $day = dedDay($event);
    dedSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    expect(fn () => (new DeleteEventDay(new RemoveExclusion))($day, dedAdmin()))
        ->toThrow(EventDayHasStartedSessionException::class);

    expect(EventDay::find($day->id))->not->toBeNull();
});

it('throws EventDayHasStartedSessionException for a day with an ongoing session', function () {
    $event = dedEvent();
    $day = dedDay($event);
    dedSession($day, ['status' => SessionStatus::Ongoing]);

    (new DeleteEventDay(new RemoveExclusion))($day, dedAdmin());
})->throws(EventDayHasStartedSessionException::class);

it('throws when the parent event has already ended', function () {
    $event = dedEvent(['status' => EventStatus::Ended]);
    $day = dedDay($event);

    (new DeleteEventDay(new RemoveExclusion))($day, dedAdmin());
})->throws(EventAlreadyEndedException::class);
