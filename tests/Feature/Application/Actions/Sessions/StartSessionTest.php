<?php

use App\Application\Actions\Sessions\StartSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventHasOngoingSessionException;
use App\Domain\Exceptions\SessionNotScheduledException;
use App\Domain\Exceptions\SessionStartTooEarlyException;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Every fixture below is anchored to a fixed calendar date (2026-11-10)
// with start times no later than 17:00 — freezing "now" late that same
// day (rather than leaving it as real wall-clock time) keeps every
// existing "does this transition succeed" test past every fixture's
// start time, the same way ScanAttendanceTest freezes its clock inside
// the session's window. The new SessionStartTooEarlyException tests
// below move the clock earlier on their own, on top of this.
beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 23:59:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

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

// --- Server-clock gate (SessionStartTooEarlyException) ---
//
// The frontend disables the Start button until a session's scheduled
// start time arrives, but that check runs against the *device's* clock —
// an admin could set their device's clock back and still tap a button
// that renders as enabled. StartSession itself must refuse to start
// early regardless, using Carbon::now() (the application server's own
// clock) rather than anything the request supplies.

it('refuses to start a session before its scheduled start time', function () {
    $session = startSessionFixture(['start_time' => '07:00:00']);

    // One minute before the 07:00 scheduled start, on the session's own
    // calendar date.
    Carbon::setTestNow(Carbon::parse('2026-11-10 06:59:00', 'Asia/Manila'));

    (new StartSession)($session);
})->throws(SessionStartTooEarlyException::class);

it('allows starting a session exactly at its scheduled start time', function () {
    $session = startSessionFixture(['start_time' => '07:00:00']);

    Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'Asia/Manila'));

    $started = (new StartSession)($session);

    expect($started->status)->toBe(SessionStatus::Ongoing);
});

it('allows starting a session after its scheduled start time', function () {
    $session = startSessionFixture(['start_time' => '07:00:00']);

    Carbon::setTestNow(Carbon::parse('2026-11-10 07:15:00', 'Asia/Manila'));

    $started = (new StartSession)($session);

    expect($started->status)->toBe(SessionStatus::Ongoing);
});

it('refuses to start a session on an earlier calendar day even at the same clock time', function () {
    // Same wall-clock time as the session's start_time, but the day
    // before its event day's date — guards against a naive string-only
    // time comparison that ignores the date entirely.
    $session = startSessionFixture(['start_time' => '07:00:00']);

    Carbon::setTestNow(Carbon::parse('2026-11-09 07:00:00', 'Asia/Manila'));

    (new StartSession)($session);
})->throws(SessionStartTooEarlyException::class);

it('does not let a spoofed device clock bypass the early-start gate', function () {
    // Simulates an admin whose device clock has been wound forward past
    // the scheduled start time, sending that as if it were "now" — but
    // StartSession never reads a client-supplied timestamp at all, only
    // Carbon::now() (this test's frozen *server* clock). So even though
    // the (unused) $spoofedDeviceNow below claims 07:00 has arrived, the
    // real (frozen) server clock is still 06:00, and the start is refused.
    $session = startSessionFixture(['start_time' => '07:00:00']);

    $spoofedDeviceNow = Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')->toIso8601String();
    Carbon::setTestNow(Carbon::parse('2026-11-10 06:00:00', 'Asia/Manila'));

    expect($spoofedDeviceNow)->toContain('07:05:00'); // sanity: a real timestamp, just never consulted

    (new StartSession)($session);
})->throws(SessionStartTooEarlyException::class);

it('includes the scheduled start time in the early-start error message', function () {
    $session = startSessionFixture(['start_time' => '14:45:00']);

    Carbon::setTestNow(Carbon::parse('2026-11-10 14:44:00', 'Asia/Manila'));

    try {
        (new StartSession)($session);
        $this->fail('Expected SessionStartTooEarlyException to be thrown.');
    } catch (SessionStartTooEarlyException $exception) {
        expect($exception->getMessage())->toContain('2:45 PM');
    }
});
