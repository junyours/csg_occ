<?php

use App\Application\Actions\Dashboard\BuildDashboardSummary;
use App\Application\Actions\Events\EndEvent;
use App\Application\Actions\Sessions\EndSession;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Application\Actions\Sessions\StartSession;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function dashboardDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function dashboardAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => dashboardDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );
}

function dashboardStudent(string $studentNumber): Student
{
    $student = Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => dashboardDept('CCS')->id,
        'username' => 'user'.$studentNumber,
        'password' => 'password',
    ]);

    // Eloquent doesn't hydrate DB-side column defaults (qr_version
    // defaults to 1 at the schema level) back onto the in-memory model
    // after create() — same gotcha CreateStudent::__invoke() works
    // around. Without this, $student->qr_version stays null here even
    // though the row itself has 1, and QrPayload::forStudent() (which
    // requires a non-null int) blows up below.
    return $student->refresh();
}

it('keeps overall attendance counts and penalty totals after the session and event have ended', function () {
    $admin = dashboardAdmin();
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'penalty_absent_amount' => 25,
        'status' => SessionStatus::Scheduled,
    ]);

    $present = dashboardStudent('2023000001');
    dashboardStudent('2023000002'); // will end up Absent, charged the 25 penalty

    // StartSession fetches and updates its own locked copy of the row
    // rather than mutating the $session instance passed in, so the
    // original $session object never sees status flip to Ongoing unless
    // we capture the return value here — otherwise ScanAttendance below
    // checks a stale in-memory 'scheduled' status and rejects the scan.
    $session = (new StartSession)($session);

    $token = QrPayload::forStudent($present->student_number, $present->qr_version)->encrypt();
    (new ScanAttendance)($session, $token);

    // Ending the event cascades to end the session too — this is exactly
    // the situation the fix targets: no session is ongoing anymore, and
    // the event itself is over.
    (new EndEvent(new EndSession))($event);

    $summary = (new BuildDashboardSummary)($admin);

    expect($summary['active_session'])->toBeNull()
        ->and($summary['active_session_counts'])->toBeNull()
        // ...but the all-time counts and penalty totals are still there.
        ->and($summary['overall_attendance_counts'])->toBe([
            'present' => 1,
            'late' => 0,
            'absent' => 1,
        ])
        ->and($summary['penalty_total'])->toBe(25.0)
        ->and($summary['penalty_by_event'])->toBe([
            ['event_id' => $event->id, 'event_name' => 'Intramurals 2026', 'penalty_total' => 25.0, 'percentage_of_overall' => 100.0],
        ])
        ->and($summary['attendance_by_department'])->toBe([
            [
                'department_id' => dashboardDept('CCS')->id,
                'department_name' => 'CCS',
                'department_code' => 'CCS',
                'present' => 1,
                'late' => 0,
                'absent' => 1,
                'tracked' => 2,
                'percentage_of_overall' => 100.0,
            ],
        ])
        ->and($summary['penalty_by_department'])->toBe([
            [
                'department_id' => dashboardDept('CCS')->id,
                'department_name' => 'CCS',
                'department_code' => 'CCS',
                'penalty_total' => 25.0,
                'percentage_of_overall' => 100.0,
            ],
        ]);
});

it('splits penalty totals across departments', function () {
    $admin = dashboardAdmin();
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'penalty_absent_amount' => 25,
        'status' => SessionStatus::Scheduled,
    ]);

    // Both students never scan, so both are charged the absent penalty —
    // one per department, so the split should be an even 50/50.
    dashboardStudent('2023000001'); // CCS
    $otherDept = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    Student::create([
        'student_number' => '2023000002',
        'last_name' => 'Other', 'first_name' => 'Dept',
        'department_id' => $otherDept->id,
        'username' => 'user2023000002', 'password' => 'password',
    ]);

    $session = (new StartSession)($session);
    (new EndSession)($session);

    $summary = (new BuildDashboardSummary)($admin);

    expect($summary['penalty_total'])->toBe(50.0)
        ->and(collect($summary['penalty_by_department'])->pluck('percentage_of_overall', 'department_code')->all())
        ->toBe(['CCS' => 50.0, 'BSIT' => 50.0]);
});

it('scopes overall attendance counts to the sc admin\'s own department', function () {
    $admin = dashboardAdmin();
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'status' => SessionStatus::Scheduled,
    ]);

    $ccsStudent = dashboardStudent('2023000001');
    $otherDept = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    Student::create([
        'student_number' => '2023000002',
        'last_name' => 'Other', 'first_name' => 'Dept',
        'department_id' => $otherDept->id,
        'username' => 'user2023000002', 'password' => 'password',
    ]);

    // See the note in the previous test: capture StartSession's return
    // value, since it mutates its own locked copy of the row rather
    // than this $session instance.
    $session = (new StartSession)($session);
    $token = QrPayload::forStudent($ccsStudent->student_number, $ccsStudent->qr_version)->encrypt();
    (new ScanAttendance)($session, $token);
    (new EndSession)($session);

    $scAdmin = Student::create([
        'student_number' => '2020100001',
        'last_name' => 'SC', 'first_name' => 'Admin',
        'department_id' => dashboardDept('CCS')->id,
        'username' => 'scadmin', 'password' => 'password',
        'role' => Role::ScAdmin,
        'sc_admin_department_id' => dashboardDept('CCS')->id,
    ]);

    $summary = (new BuildDashboardSummary)($scAdmin);

    // Only the CCS student's outcome counts — the BSIT student (absent,
    // since they never scanned) is out of scope for this SC Admin.
    expect($summary['overall_attendance_counts'])->toBe([
        'present' => 1,
        'late' => 0,
        'absent' => 0,
    ]);
});
