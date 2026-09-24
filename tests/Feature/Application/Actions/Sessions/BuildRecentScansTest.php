<?php

use App\Application\Actions\Sessions\BuildRecentScans;
use App\Application\Actions\Sessions\ReverseAttendanceRecord;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceRecord;
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

function recentScansDepartment(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function recentScansStudent(string $studentNumber, string $username, Role $role = Role::Student): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => recentScansDepartment()->id,
        'username' => $username,
        'password' => 'password',
        'qr_version' => 1,
        'role' => $role,
    ]);
}

function recentScansSession(array $overrides = []): AttendanceSession
{
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => recentScansDepartment()->id,
            'username' => 'csgadmin', 'password' => 'password',
        ],
    );

    $event = EventModel::firstOrCreate(['name' => 'Intrams'], ['created_by' => $creator->id]);

    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => 1],
        ['date' => '2026-11-10'],
    );

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ongoing,
    ], $overrides));
}

/** Scan $studentNumber into $session, advancing the clock a minute first so ordering is deterministic. */
function recentScansScan(AttendanceSession $session, Student $student, Student $officer, string $at): AttendanceRecord
{
    Carbon::setTestNow(Carbon::parse("2026-11-10 {$at}", 'Asia/Manila'));

    return (new ScanAttendance)(
        $session,
        QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt(),
        $officer->id,
    );
}

it('returns this session\'s scans newest first', function () {
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScansSession();

    $first = recentScansStudent('2023100001', 'one');
    $second = recentScansStudent('2023100002', 'two');
    $third = recentScansStudent('2023100003', 'three');

    recentScansScan($session, $first, $officer, '07:01:00');
    recentScansScan($session, $second, $officer, '07:02:00');
    recentScansScan($session, $third, $officer, '07:03:00');

    $recent = (new BuildRecentScans)($session);

    expect($recent->pluck('student_id')->all())->toBe([$third->id, $second->id, $first->id]);
});

it('never leaks scans from another session', function () {
    // The bug this endpoint exists to fix: an officer scanning the
    // time-out window should not see the morning time-in queue, and
    // certainly not another event's.
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $timeIn = recentScansSession();
    $timeOut = recentScansSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);

    $morningStudent = recentScansStudent('2023100001', 'one');
    $afternoonStudent = recentScansStudent('2023100002', 'two');

    recentScansScan($timeIn, $morningStudent, $officer, '07:01:00');
    recentScansScan($timeOut, $afternoonStudent, $officer, '16:05:00');

    expect((new BuildRecentScans)($timeIn)->pluck('student_id')->all())->toBe([$morningStudent->id])
        ->and((new BuildRecentScans)($timeOut)->pluck('student_id')->all())->toBe([$afternoonStudent->id]);
});

it('drops a reversed scan out of the list', function () {
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScansSession();

    $kept = recentScansStudent('2023100001', 'one');
    $impostorScan = recentScansStudent('2023100002', 'two');

    recentScansScan($session, $kept, $officer, '07:01:00');
    $wrong = recentScansScan($session, $impostorScan, $officer, '07:02:00');

    (new ReverseAttendanceRecord)($session, $wrong, $officer);

    expect((new BuildRecentScans)($session)->pluck('student_id')->all())->toBe([$kept->id]);
});

it('hides the absent rows a closing session backfills', function () {
    // EndSession writes an AttendanceRecord per no-show with a null
    // scanned_at. Those are records, but nobody scanned them — without
    // the whereNotNull guard they'd bury the actual scans.
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScansSession();
    $scanned = recentScansStudent('2023100001', 'one');
    $noShow = recentScansStudent('2023100002', 'two');

    recentScansScan($session, $scanned, $officer, '07:01:00');

    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $noShow->id,
        'scanned_at' => null,
        'status' => AttendanceStatus::Absent,
        'scanned_by' => null,
    ]);

    expect((new BuildRecentScans)($session)->pluck('student_id')->all())->toBe([$scanned->id]);
});

it('honours the limit and clamps anything out of range', function () {
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScansSession();

    $students = collect(range(1, 4))->map(
        fn (int $n) => recentScansStudent('202310000'.$n, 'student'.$n),
    );

    foreach ($students as $index => $student) {
        recentScansScan($session, $student, $officer, sprintf('07:0%d:00', $index + 1));
    }

    $build = new BuildRecentScans;

    expect($build($session, 2)->pluck('student_id')->all())
        ->toBe([$students[3]->id, $students[2]->id])
        // A zero/negative limit is floored to 1 rather than returning an
        // empty list or throwing a database error.
        ->and($build($session, 0))->toHaveCount(1)
        ->and($build($session, 999))->toHaveCount(4);
});

it('eager loads the student and department the scan strip renders', function () {
    // The strip shows a photo, a name, and a department/section line for
    // each row — without the eager load that's an N+1 on every poll.
    $officer = recentScansStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScansSession();
    $student = recentScansStudent('2023100001', 'one');

    recentScansScan($session, $student, $officer, '07:01:00');

    $entry = (new BuildRecentScans)($session)->first();

    expect($entry->relationLoaded('student'))->toBeTrue()
        ->and($entry->student->relationLoaded('department'))->toBeTrue()
        ->and($entry->student->department->code)->toBe('CS');
});
