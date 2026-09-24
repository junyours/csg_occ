<?php

use App\Application\Actions\Sessions\ReverseAttendanceRecord;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\AttendanceRecordNotReversibleException;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordReversal;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Same frozen instant the rest of the scanning suite uses — inside the
// 07:00–08:00 window of the fixture day below, and inside its 15-minute
// grace period, so a plain scan classifies as Present.
beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function revScanDepartment(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function revScanStudent(string $studentNumber = '2023105413', string $username = 'jcruz', Role $role = Role::Student): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => revScanDepartment()->id,
        'username' => $username,
        'password' => 'password',
        'qr_version' => 1,
        'role' => $role,
    ]);
}

function revScanOfficer(string $studentNumber = '2020100001', string $username = 'officer1'): Student
{
    return revScanStudent($studentNumber, $username, Role::Officer);
}

function revScanSession(array $overrides = []): AttendanceSession
{
    // firstOrCreate on department/event/day so a test that needs a second
    // session "on the same day" reuses the same rows instead of colliding
    // on the department's unique `code`.
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => revScanDepartment()->id,
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

it('deletes the record and writes an audit row carrying the full snapshot', function () {
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token, $officer->id);
    expect($record->status)->toBe(AttendanceStatus::Present);

    $reversal = (new ReverseAttendanceRecord)($session, $record, $officer, 'QR belongs to someone else');

    // The record is gone, not flagged — "pending" in this app is the
    // absence of a row, which is what every reader already understands.
    expect(AttendanceRecord::count())->toBe(0);

    expect(AttendanceRecordReversal::count())->toBe(1)
        ->and($reversal->session_id)->toBe($session->id)
        ->and($reversal->student_id)->toBe($student->id)
        // The snapshot: the deleted row's own classification, its scan
        // time, and who scanned it, all preserved on the audit row since
        // there's no longer a record to read them back from.
        ->and($reversal->status)->toBe(AttendanceStatus::Present)
        ->and($reversal->scanned_at->equalTo($record->scanned_at))->toBeTrue()
        ->and($reversal->scanned_by)->toBe($officer->id)
        ->and($reversal->reversed_by)->toBe($officer->id)
        ->and($reversal->reason)->toBe('QR belongs to someone else');
});

it('lets the real owner scan in again after a reversal', function () {
    // The whole point of the feature: the impostor's scan took the
    // student's one slot (unique index on session_id + student_id), and
    // reversing has to actually free it up again rather than leaving
    // them permanently unable to check in.
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $scan = new ScanAttendance;

    $first = $scan($session, $token, $officer->id);
    (new ReverseAttendanceRecord)($session, $first, $officer);

    Carbon::setTestNow(Carbon::parse('2026-11-10 07:10:00', 'Asia/Manila'));

    $second = $scan($session, $token, $officer->id);

    expect(AttendanceRecord::count())->toBe(1)
        ->and($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe(AttendanceStatus::Present)
        // A genuinely new scan, stamped at the later instant — not the
        // original row resurrected with its old timestamp.
        ->and($second->scanned_at->greaterThan($first->scanned_at))->toBeTrue();
});

it('falls back to a default reason when the officer does not type one', function () {
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token, $officer->id);

    $reversal = (new ReverseAttendanceRecord)($session, $record, $officer);

    // Not an empty string: the audit column is NOT NULL precisely
    // because a blank reason would make the trail useless.
    expect($reversal->reason)->toBe(ReverseAttendanceRecord::DEFAULT_REASON);
});

it('treats a whitespace-only reason as no reason at all', function () {
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token, $officer->id);

    $reversal = (new ReverseAttendanceRecord)($session, $record, $officer, '   ');

    expect($reversal->reason)->toBe(ReverseAttendanceRecord::DEFAULT_REASON);
});

it('refuses to reverse once the session has ended, leaving the record intact', function () {
    // After EndSession runs, the absent sweep and the penalty ledger
    // writes have already happened — deleting a record then would drop
    // the student out of the session's reports entirely instead of
    // returning them to pending.
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token, $officer->id);

    $session->update(['status' => SessionStatus::Ended]);

    expect(fn () => (new ReverseAttendanceRecord)($session, $record, $officer))
        ->toThrow(AttendanceRecordNotReversibleException::class);

    expect(AttendanceRecord::count())->toBe(1)
        ->and(AttendanceRecordReversal::count())->toBe(0);
});

it('refuses to reverse a record that belongs to a different session', function () {
    // {session} and {record} are bound independently in the route, so
    // pointing a record id from a session you can't touch at one you can
    // must not work.
    $officer = revScanOfficer();
    $student = revScanStudent();
    $timeIn = revScanSession();
    $timeOut = revScanSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($timeIn, $token, $officer->id);

    expect(fn () => (new ReverseAttendanceRecord)($timeOut, $record, $officer))
        ->toThrow(ModelNotFoundException::class);

    expect(AttendanceRecord::count())->toBe(1)
        ->and(AttendanceRecordReversal::count())->toBe(0);
});

it('keeps one audit row per reversal when the same student is scanned and reversed twice', function () {
    // An impostor tries again after being caught: each attempt is its
    // own record and its own audit row, so the trail shows both.
    $officer = revScanOfficer();
    $student = revScanStudent();
    $session = revScanSession();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $scan = new ScanAttendance;
    $reverse = new ReverseAttendanceRecord;

    $reverse($session, $scan($session, $token, $officer->id), $officer, 'First attempt');

    Carbon::setTestNow(Carbon::parse('2026-11-10 07:20:00', 'Asia/Manila'));

    $reverse($session, $scan($session, $token, $officer->id), $officer, 'Second attempt');

    expect(AttendanceRecord::count())->toBe(0)
        ->and(AttendanceRecordReversal::count())->toBe(2)
        ->and(AttendanceRecordReversal::orderBy('id')->pluck('reason')->all())
        ->toBe(['First attempt', 'Second attempt']);
});
