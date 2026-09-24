<?php

use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Application\Actions\Sessions\DeleteSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\SessionAlreadyStartedException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function dsDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function dsAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => dsDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function dsStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => dsDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function dsEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => dsAdmin()->id], $overrides));
}

function dsDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function dsSession(EventDay $day, array $overrides = []): AttendanceSession
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

function dsExclusion(EventModel $event, Student $student, Student $creator, array $overrides = []): Exclusion
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

it('deletes a scheduled session with no sibling check', function () {
    $event = dsEvent();
    $day = dsDay($event);
    $session = dsSession($day);

    $summary = (new DeleteSession(new RemoveExclusion))($session, dsAdmin());

    expect($summary['session_id'])->toBe($session->id);
    expect(AttendanceSession::find($session->id))->toBeNull();
});

it('deleting one check while its sibling check survives does not revoke the window exclusion', function () {
    $admin = dsAdmin();
    $student = dsStudent();
    $event = dsEvent();
    $day = dsDay($event);
    $timeIn = dsSession($day, ['check_type' => CheckType::TimeIn]);
    dsSession($day, ['check_type' => CheckType::TimeOut, 'start_time' => '16:00:00', 'end_time' => '17:00:00']);
    $exclusion = dsExclusion($event, $student, $admin, [
        'event_day_id' => $day->id, 'window_type' => WindowType::Morning,
    ]);

    $summary = (new DeleteSession(new RemoveExclusion))($timeIn, $admin);

    expect($summary['exclusions_removed'])->toBe(0);
    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('deleting the last remaining check of a window revokes its active window exclusion', function () {
    $admin = dsAdmin();
    $student = dsStudent();
    $event = dsEvent();
    $day = dsDay($event);
    $onlyCheck = dsSession($day, ['check_type' => CheckType::TimeIn]);
    $exclusion = dsExclusion($event, $student, $admin, [
        'event_day_id' => $day->id, 'window_type' => WindowType::Morning,
    ]);

    $summary = (new DeleteSession(new RemoveExclusion))($onlyCheck, $admin);

    expect($summary['exclusions_removed'])->toBe(1);
    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Removed)
        ->and($exclusion->removed_by)->toBe($admin->id);
});

it('does not touch a window exclusion for a different window_type on the same day', function () {
    $admin = dsAdmin();
    $student = dsStudent();
    $event = dsEvent();
    $day = dsDay($event);
    $morningOnlyCheck = dsSession($day, ['window_type' => WindowType::Morning, 'check_type' => CheckType::TimeIn]);
    $afternoonExclusion = dsExclusion($event, $student, $admin, [
        'event_day_id' => $day->id, 'window_type' => WindowType::Afternoon,
    ]);

    (new DeleteSession(new RemoveExclusion))($morningOnlyCheck, $admin);

    expect($afternoonExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});

it('throws when the session is already ongoing', function () {
    $event = dsEvent();
    $day = dsDay($event);
    $session = dsSession($day, ['status' => SessionStatus::Ongoing]);

    (new DeleteSession(new RemoveExclusion))($session, dsAdmin());
})->throws(SessionAlreadyStartedException::class);

it('throws when the session has already ended', function () {
    $event = dsEvent();
    $day = dsDay($event);
    $session = dsSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    (new DeleteSession(new RemoveExclusion))($session, dsAdmin());
})->throws(SessionAlreadyStartedException::class);

it('throws when the parent event has already ended', function () {
    $event = dsEvent(['status' => EventStatus::Ended]);
    $day = dsDay($event);
    $session = dsSession($day);

    (new DeleteSession(new RemoveExclusion))($session, dsAdmin());
})->throws(EventAlreadyEndedException::class);
