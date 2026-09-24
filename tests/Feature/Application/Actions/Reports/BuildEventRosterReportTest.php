<?php

use App\Application\Actions\Penalties\ReversePenalty;
use App\Application\Actions\Reports\BuildEventRosterReport;
use App\Application\Actions\Sessions\EndSession;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendancePenalty;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function rosterDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function rosterEvent(): EventModel
{
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => rosterDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );

    return EventModel::create(['name' => 'Test Event', 'created_by' => $creator->id]);
}

function rosterAdmin(): Student
{
    // The same account rosterEvent() creates as the event's author —
    // firstOrCreate so either helper can be reached first.
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => rosterDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );
}

function rosterSession(EventModel $event, int $dayNumber = 1, array $overrides = []): AttendanceSession
{
    // firstOrCreate, not create: two sessions on the same day (e.g.
    // morning + afternoon) share one EventDay row — event_days has a
    // unique constraint on (event_id, day_number), so creating a fresh
    // row per call breaks as soon as a test asks for a second window on
    // the same day.
    // Explicit Asia/Manila, not the bare now() helper: the frozen test
    // instant is set in Asia/Manila (07:05 local), but now() renders in
    // the app's default timezone (UTC), which is a day behind at that
    // hour — using it here would silently shift every session's date
    // back a day and push every scan outside its grace window.
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => $dayNumber],
        ['date' => Carbon::now('Asia/Manila')->addDays($dayNumber - 1)->toDateString()],
    );

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
        'status' => SessionStatus::Ongoing,
    ], $overrides));
}

function rosterStudent(string $studentNumber, string $deptCode = 'BSIT', array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => rosterDept($deptCode)->id,
        'year_level' => '1', 'section' => 'A',
        'username' => 'user'.$studentNumber,
        'password' => 'password',
        'qr_version' => 1,
    ], $overrides));
}

it('groups students by department, year level, and section', function () {
    $event = rosterEvent();
    rosterSession($event);
    rosterStudent('2023000001', 'BSIT', ['year_level' => '1', 'section' => 'A']);
    rosterStudent('2023000002', 'BSIT', ['year_level' => '1', 'section' => 'B']);
    rosterStudent('2023000003', 'BSBA', ['year_level' => '1', 'section' => 'A']);

    $report = (new BuildEventRosterReport)($event);

    $keys = collect($report['groups'])->map(fn ($g) => "{$g['department_code']}-{$g['year_level']}{$g['section']}")->all();
    expect($report['groups'])->toHaveCount(3)
        ->and($keys)->toBe(['BSBA-1A', 'BSIT-1A', 'BSIT-1B']);
});

it('sorts students within a group a-z by last name', function () {
    $event = rosterEvent();
    rosterSession($event);
    rosterStudent('2023000004', 'BSIT', ['last_name' => 'Zamora']);
    rosterStudent('2023000005', 'BSIT', ['last_name' => 'Aquino']);

    $report = (new BuildEventRosterReport)($event);

    $names = collect($report['groups'][0]['students'])->pluck('last_name')->all();
    expect($names)->toBe(['Aquino', 'Zamora']);
});

it('reports one column per session with a pending status while sessions are still open', function () {
    $event = rosterEvent();
    $morning = rosterSession($event, 1, ['window_type' => WindowType::Morning]);
    $afternoon = rosterSession($event, 1, ['window_type' => WindowType::Afternoon]);
    rosterStudent('2023000006');

    $report = (new BuildEventRosterReport)($event);

    $sessions = $report['groups'][0]['students'][0]['sessions'];
    expect($report['sessions'])->toHaveCount(2)
        ->and($sessions[$morning->id])->toBeNull()
        ->and($sessions[$afternoon->id])->toBeNull();
});

it('reflects a present scan and an ended-session absence across different sessions for the same student', function () {
    $event = rosterEvent();
    $scannedSession = rosterSession($event, 1, ['window_type' => WindowType::Morning]);
    $endedSession = rosterSession($event, 2, ['window_type' => WindowType::Morning]);
    $student = rosterStudent('2023000007');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    (new ScanAttendance)($scannedSession, $token);
    (new EndSession)($endedSession);

    $report = (new BuildEventRosterReport)($event);
    $sessions = $report['groups'][0]['students'][0]['sessions'];

    expect($sessions[$scannedSession->id])->toBe('present')
        ->and($sessions[$endedSession->id])->toBe('absent')
        ->and($report['groups'][0]['students'][0]['penalty_total'])->toBe(25.0);
});

it('shows an event-wide excluded student as excluded rather than absent after the session ends', function () {
    $event = rosterEvent();
    $session = rosterSession($event);
    $student = rosterStudent('2023000008');

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $event->created_by,
    ]);

    (new EndSession)($session);
    $report = (new BuildEventRosterReport)($event);

    expect($report['groups'][0]['students'][0]['sessions'][$session->id])->toBe('excluded')
        ->and($report['groups'][0]['students'][0]['penalty_total'])->toBe(0.0);
});

it('sums penalties across sessions into a group total', function () {
    $event = rosterEvent();
    $sessionOne = rosterSession($event, 1);
    $sessionTwo = rosterSession($event, 2);
    rosterStudent('2023000009');
    rosterStudent('2023000010');

    (new EndSession)($sessionOne);
    (new EndSession)($sessionTwo);

    $report = (new BuildEventRosterReport)($event);

    expect($report['groups'][0]['group_penalty_total'])->toBe(100.0); // 2 students x 2 sessions x 25
});

it('never includes staff accounts in the roster', function () {
    $event = rosterEvent(); // creates a csg_admin as the event owner
    rosterSession($event);
    rosterStudent('2023000011');

    $report = (new BuildEventRosterReport)($event);

    $numbers = collect($report['groups'][0]['students'])->pluck('student_number');
    expect($numbers)->not->toContain('2020000001');
});

it('filters the roster to a single department, year level, and section', function () {
    $event = rosterEvent();
    rosterSession($event);
    rosterStudent('2023000012', 'BSIT', ['year_level' => '1', 'section' => 'A']);
    rosterStudent('2023000013', 'BSIT', ['year_level' => '1', 'section' => 'B']);
    rosterStudent('2023000014', 'BSBA', ['year_level' => '1', 'section' => 'A']);
    $bsit = rosterDept('BSIT');

    $report = (new BuildEventRosterReport)($event, $bsit->id, null, '1', 'A');

    expect($report['groups'])->toHaveCount(1)
        ->and($report['groups'][0]['students'])->toHaveCount(1)
        ->and($report['groups'][0]['students'][0]['student_number'])->toBe('2023000012');
});

it('reads a reversed absence as reversed rather than absent, and stops charging for it', function () {
    $event = rosterEvent();
    $session = rosterSession($event);
    $student = rosterStudent('2023000020');

    (new EndSession)($session);

    // The precondition this test is really about: before the reversal the
    // cell is a plain Absent worth the session's absent penalty.
    $before = (new BuildEventRosterReport)($event)['groups'][0]['students'][0];
    expect($before['sessions'][$session->id])->toBe('absent')
        ->and($before['penalty_total'])->toBe(25.0);

    $penalty = AttendancePenalty::where('student_id', $student->id)
        ->where('session_id', $session->id)
        ->firstOrFail();

    (new ReversePenalty)($penalty, 'Medical certificate on file', rosterAdmin());

    $after = (new BuildEventRosterReport)($event)['groups'][0]['students'][0];

    expect($after['sessions'][$session->id])->toBe('reversed')
        ->and($after['penalty_total'])->toBe(0.0);
});

it('leaves an excluded session reading excluded even when a penalty for it was reversed', function () {
    $event = rosterEvent();
    $session = rosterSession($event);
    $student = rosterStudent('2023000022');

    // The exclusion has to exist *before* the session closes: per
    // student-exclusion-feature-plan.md §6a, an event-scope exclusion is
    // never retroactive, so one added afterwards would not cover this
    // session at all. EndSession freezes the outcome in as a permanent
    // `excluded` AttendanceRecord and charges nothing for it.
    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $event->created_by,
    ]);

    (new EndSession)($session);

    expect(AttendancePenalty::where('student_id', $student->id)->where('session_id', $session->id)->exists())
        ->toBeFalse();

    // A stray penalty on the same session — attendance_penalties has no
    // unique (student_id, session_id) key, so a mistaken manual charge is
    // reachable — which is then reversed. The reversal must not relabel
    // the cell "Reversed": "was never expected to attend" is a different
    // statement from "was charged and then forgiven", and the frozen
    // record is the one the plan says survives.
    $penalty = AttendancePenalty::create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'amount' => 25,
        'reason' => 'Absent - Time In (charged in error)',
    ]);
    (new ReversePenalty)($penalty, 'Student was excluded, charged in error', rosterAdmin());

    expect((new BuildEventRosterReport)($event)['groups'][0]['students'][0]['sessions'][$session->id])
        ->toBe('excluded');
});

it('does not let an event-scope exclusion added after a session ended rewrite its history', function () {
    $event = rosterEvent();
    $session = rosterSession($event);
    $student = rosterStudent('2023000024');

    (new EndSession)($session);

    // Unambiguously later than the session's ended_at, so this is not
    // resting on same-instant timestamp comparison.
    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));

    // §2 rule 1 / §6a: the session had already ended when this was
    // created, so it is outside the cascade entirely — Absent stands.
    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $event->created_by,
    ]);

    expect((new BuildEventRosterReport)($event)['groups'][0]['students'][0]['sessions'][$session->id])
        ->toBe('absent');
});

it('does not read as reversed while a live penalty for the same session survives', function () {
    $event = rosterEvent();
    $session = rosterSession($event);
    $student = rosterStudent('2023000023');

    (new EndSession)($session);

    $reversed = AttendancePenalty::where('student_id', $student->id)
        ->where('session_id', $session->id)
        ->firstOrFail();
    (new ReversePenalty)($reversed, 'Reversed in error', rosterAdmin());

    // A second, still-live charge on the same session. attendance_penalties
    // has no unique (student_id, session_id) key and EndSession's
    // firstOrCreate keys on reason too, so this is reachable — and while the
    // student still owes money the cell must not claim to be forgiven.
    AttendancePenalty::create([
        'student_id' => $student->id,
        'session_id' => $session->id,
        'amount' => 25,
        'reason' => 'Absent - Time In (re-charged)',
    ]);

    $row = (new BuildEventRosterReport)($event)['groups'][0]['students'][0];

    expect($row['sessions'][$session->id])->toBe('absent')
        ->and($row['penalty_total'])->toBe(25.0);
});
