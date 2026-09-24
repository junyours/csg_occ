<?php

use App\Application\Actions\Sessions\BuildSessionReport;
use App\Application\Actions\Sessions\EndSession;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\QrPayload;
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

function reportDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function reportEvent(): EventModel
{
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => reportDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );

    return EventModel::create(['name' => 'Test Event', 'created_by' => $creator->id]);
}

function reportSession(array $overrides = []): AttendanceSession
{
    $day = EventDay::create([
        'event_id' => reportEvent()->id,
        'date' => '2026-11-10',
        'day_number' => 1,
    ]);

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

function reportStudent(string $studentNumber, string $deptCode = 'CS', array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => reportDept($deptCode)->id,
        'username' => 'user'.$studentNumber,
        'password' => 'password',
        // Eloquent doesn't backfill a DB-side default (qr_version = 1) into
        // the in-memory model after create() — without this, $student->qr_version
        // is null right after creation until a refresh(), which broke
        // QrPayload::forStudent() below in the very next test.
        'qr_version' => 1,
    ], $overrides));
}

it('reports a not-yet-scanned student as pending, not absent, while the session is still open', function () {
    $session = reportSession();
    reportStudent('2023000001');

    $report = (new BuildSessionReport)($session);

    expect($report['summary']['pending'])->toBe(1)
        ->and($report['summary']['absent'])->toBe(0)
        ->and($report['students'][0]['status'])->toBeNull();
});

it('reports a present scan correctly and includes their penalty amount', function () {
    $session = reportSession();
    $student = reportStudent('2023000002');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    (new ScanAttendance)($session, $token);

    $report = (new BuildSessionReport)($session);

    expect($report['summary']['present'])->toBe(1)
        ->and($report['students'][0]['status'])->toBe('present')
        ->and($report['students'][0]['penalty_amount'])->toBe(0.0);
});

it('reports an excluded student as excluded rather than absent, even after the session ends', function () {
    $session = reportSession();
    $event = $session->eventDay->event;
    $excluded = reportStudent('2023000003');

    Exclusion::create([
        'student_id' => $excluded->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $event->created_by,
    ]);

    (new EndSession)($session);
    $report = (new BuildSessionReport)($session);

    expect($report['summary']['excluded'])->toBe(1)
        ->and($report['summary']['absent'])->toBe(0)
        ->and($report['students'][0]['status'])->toBe('excluded')
        ->and($report['students'][0]['penalty_amount'])->toBe(0.0);
});

it('reports absent students with their penalty amount once the session has ended', function () {
    $session = reportSession();
    reportStudent('2023000004');

    (new EndSession)($session);
    $report = (new BuildSessionReport)($session);

    expect($report['summary']['absent'])->toBe(1)
        ->and($report['summary']['pending'])->toBe(0)
        ->and($report['students'][0]['status'])->toBe('absent')
        ->and($report['students'][0]['penalty_amount'])->toBe(25.0);
});

it('reports the window and check type this session covers', function () {
    $session = reportSession(['check_type' => CheckType::TimeOut]);
    reportStudent('2023000005');

    $report = (new BuildSessionReport)($session);

    expect($report['window_type'])->toBe('morning')
        ->and($report['check_type'])->toBe('time_out');
});

it('scopes the roster and summary counts to a single department when one is given', function () {
    $session = reportSession();
    reportStudent('2023000006', 'CS');
    reportStudent('2023000007', 'EDUC');
    $cs = reportDept('CS');

    $report = (new BuildSessionReport)($session, $cs->id);

    expect($report['students'])->toHaveCount(1)
        ->and($report['students'][0]['student_number'])->toBe('2023000006')
        ->and($report['summary']['pending'])->toBe(1);
});

it('never includes staff accounts (csg_admin, sc_admin, officer) in the roster', function () {
    $session = reportSession(); // creates a csg_admin as the event owner
    reportStudent('2023000008');

    $report = (new BuildSessionReport)($session);

    expect($report['students'])->toHaveCount(1)
        ->and(collect($report['students'])->pluck('student_number'))->not->toContain('2020000001');
});

it('sorts the roster by last name', function () {
    $session = reportSession();
    reportStudent('2023000009', 'CS', ['last_name' => 'Zamora']);
    reportStudent('2023000010', 'CS', ['last_name' => 'Aquino']);

    $report = (new BuildSessionReport)($session);

    $names = collect($report['students'])->pluck('last_name')->values()->all();
    expect($names)->toBe(['Aquino', 'Zamora']);
});
