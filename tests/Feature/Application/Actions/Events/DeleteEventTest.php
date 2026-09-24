<?php

use App\Application\Actions\Events\DeleteEvent;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventHasActiveExclusionsException;
use App\Domain\Exceptions\EventHasReportsException;
use App\Domain\Exceptions\EventHasStartedSessionException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\ReportGeneration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function deAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => deDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function deStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => deDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function deEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => deAdmin()->id], $overrides));
}

function deDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function deSession(EventDay $day, array $overrides = []): AttendanceSession
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

it('deletes a brand-new event with zero days', function () {
    $event = deEvent();

    (new DeleteEvent)($event);

    expect(EventModel::find($event->id))->toBeNull();
});

it('deletes an event whose days and sessions are all still scheduled, cascading them', function () {
    $event = deEvent();
    $day = deDay($event);
    $session = deSession($day);

    (new DeleteEvent)($event);

    expect(EventModel::find($event->id))->toBeNull()
        ->and(EventDay::find($day->id))->toBeNull()
        ->and(AttendanceSession::find($session->id))->toBeNull();
});

it('throws and deletes nothing when a session anywhere under the event has ended', function () {
    $event = deEvent();
    $day = deDay($event);
    deSession($day, ['status' => SessionStatus::Ended, 'ended_at' => now()]);

    expect(fn () => (new DeleteEvent)($event))->toThrow(EventHasStartedSessionException::class);

    expect(EventModel::find($event->id))->not->toBeNull();
});

it('throws EventHasStartedSessionException for an ongoing session on any day', function () {
    $event = deEvent();
    $day1 = deDay($event, 1, '2026-11-10');
    $day2 = deDay($event, 2, '2026-11-11');
    deSession($day1);
    deSession($day2, ['window_type' => WindowType::Afternoon, 'status' => SessionStatus::Ongoing, 'started_at' => now()]);

    (new DeleteEvent)($event);
})->throws(EventHasStartedSessionException::class);

it('throws when the event has already ended', function () {
    $event = deEvent(['status' => EventStatus::Ended]);

    (new DeleteEvent)($event);
})->throws(EventAlreadyEndedException::class);

it('throws EventHasActiveExclusionsException when an active exclusion references the event', function () {
    $admin = deAdmin();
    $student = deStudent();
    $event = deEvent();
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Accidentally excluded',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    expect(fn () => (new DeleteEvent)($event))->toThrow(EventHasActiveExclusionsException::class);

    expect(EventModel::find($event->id))->not->toBeNull()
        ->and(Exclusion::find($exclusion->id))->not->toBeNull();
});

it('deletes the event once its only exclusion has already been reversed (removed)', function () {
    $admin = deAdmin();
    $student = deStudent();
    $event = deEvent();
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Accidentally excluded, then reversed',
        'status' => ExclusionStatus::Removed,
        'created_by' => $admin->id,
        'removed_by' => $admin->id,
        'removed_at' => now(),
    ]);

    (new DeleteEvent)($event);

    expect(EventModel::find($event->id))->toBeNull()
        // The now-irrelevant removed exclusion is cleaned up along with
        // the event, rather than left orphaned by the FK.
        ->and(Exclusion::find($exclusion->id))->toBeNull();
});

it('throws EventHasReportsException when a report generation references the event, even with no exclusions', function () {
    $admin = deAdmin();
    $event = deEvent();
    ReportGeneration::create([
        'event_id' => $event->id,
        'format' => 'pdf',
        'requested_by' => $admin->id,
    ]);

    expect(fn () => (new DeleteEvent)($event))->toThrow(EventHasReportsException::class);

    expect(EventModel::find($event->id))->not->toBeNull();
});
