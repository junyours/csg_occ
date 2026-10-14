<?php

use App\Application\Actions\Sessions\EndSession;
use App\Application\Actions\Sessions\MarkAllStudentsPresent;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\SessionNotAcceptingScansException;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Frozen at 07:05 (inside the 07:00–08:00 window). Present spans start..end+grace,
// so the Late tests move the clock past 08:00 (08:30), not into the middle of it.
beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function markAllAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS'])->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );
}

function markAllSession(array $overrides = []): AttendanceSession
{
    $event = EventModel::create(['name' => 'Test Event', 'created_by' => markAllAdmin()->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 0,
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
        'status' => SessionStatus::Ongoing,
    ], $overrides));
}

function markAllStudent(string $number, Role $role = Role::Student, array $extra = []): Student
{
    $student = Student::create(array_merge([
        'student_number' => $number,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => Department::firstOrCreate(['code' => 'CS'], ['name' => 'CS'])->id,
        'username' => 'user'.$number,
        'password' => 'password',
        'role' => $role,
    ], $extra));

    // Eloquent doesn't hydrate DB-side column defaults (qr_version defaults
    // to 1 in the schema) back onto the model after create(), so reload it —
    // QrPayload::forStudent() requires a non-null int.
    return $student->refresh();
}

function markAllRecord(AttendanceSession $session, Student $student): ?AttendanceRecord
{
    return AttendanceRecord::where('session_id', $session->id)->where('student_id', $student->id)->first();
}

it('marks students with no record as Present, attributed to the admin', function () {
    $session = markAllSession();
    $student = markAllStudent('2023000001');

    $result = (new MarkAllStudentsPresent)($session, markAllAdmin());

    $record = markAllRecord($session, $student);
    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->scanned_at)->not->toBeNull()
        ->and($record->scanned_by)->toBe(markAllAdmin()->id)
        ->and($record->auto_present)->toBeFalse()
        ->and($record->department_id)->toBe($student->department_id)
        ->and($result['marked_present'])->toBe(1)
        ->and($result['late_converted'])->toBe(0);
});

it('does not override a student who already scanned as Present', function () {
    $session = markAllSession();
    $student = markAllStudent('2023000002');
    $officer = markAllStudent('2023000090', Role::Officer);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $original = (new ScanAttendance)($session, $token, $officer->id);
    expect($original->status)->toBe(AttendanceStatus::Present);

    $result = (new MarkAllStudentsPresent)($session, markAllAdmin());

    $record = markAllRecord($session, $student);
    expect($record->id)->toBe($original->id)
        ->and($record->scanned_by)->toBe($officer->id) // not re-attributed to the admin
        ->and($record->scanned_at->equalTo($original->scanned_at))->toBeTrue()
        ->and($result['already_present'])->toBe(1)
        ->and($result['marked_present'])->toBe(0)
        ->and(AttendanceRecord::where('session_id', $session->id)->count())->toBe(1);
});

it('turns a Late student into Present', function () {
    $session = markAllSession();
    $student = markAllStudent('2023000003');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    Carbon::setTestNow(Carbon::parse('2026-11-10 08:30:00', 'Asia/Manila'));
    $late = (new ScanAttendance)($session, $token);
    expect($late->status)->toBe(AttendanceStatus::Late);

    $result = (new MarkAllStudentsPresent)($session, markAllAdmin());

    $record = markAllRecord($session, $student);
    expect($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->scanned_at->equalTo($late->scanned_at))->toBeTrue()
        ->and($result['late_converted'])->toBe(1)
        ->and($result['marked_present'])->toBe(0);

    // The promoted record must not be charged a Late penalty when the session ends.
    (new EndSession)($session);
    expect(AttendancePenalty::where('student_id', $student->id)->count())->toBe(0);
});

it('leaves excluded students untouched, with or without a record', function () {
    $session = markAllSession();
    $event = $session->eventDay->event;

    $noRecord = markAllStudent('2023000004');
    $lateThenExcluded = markAllStudent('2023000005');
    $token = QrPayload::forStudent($lateThenExcluded->student_number, $lateThenExcluded->qr_version)->encrypt();

    Carbon::setTestNow(Carbon::parse('2026-11-10 08:30:00', 'Asia/Manila'));
    (new ScanAttendance)($session, $token); // Late, then excluded mid-window

    foreach ([$noRecord, $lateThenExcluded] as $student) {
        Exclusion::create([
            'student_id' => $student->id, 'event_id' => $event->id,
            'scope' => ExclusionScope::Event, 'reason' => 'Excused', 'created_by' => markAllAdmin()->id,
        ]);
    }

    $result = (new MarkAllStudentsPresent)($session, markAllAdmin());

    expect(markAllRecord($session, $noRecord))->toBeNull()
        ->and(markAllRecord($session, $lateThenExcluded)->status)->toBe(AttendanceStatus::Late)
        ->and($result['marked_present'])->toBe(0)
        ->and($result['late_converted'])->toBe(0);

    // ...and EndSession still gives the excluded no-record student their permanent Excluded row.
    (new EndSession)($session);
    expect(markAllRecord($session, $noRecord)->status)->toBe(AttendanceStatus::Excluded);
});

it('skips officers, admins, inactive accounts and other departments the event excludes', function () {
    $session = markAllSession();
    $event = $session->eventDay->event;

    $officer = markAllStudent('2023000006', Role::Officer);
    $sc = markAllStudent('2023000007', Role::ScAdmin);
    $inactive = markAllStudent('2023000008', Role::Student, ['status' => Student::STATUS_INACTIVE]);
    $otherDept = markAllStudent('2023000009', Role::Student, [
        'department_id' => Department::firstOrCreate(['code' => 'EDU'], ['name' => 'EDU'])->id,
    ]);
    $included = markAllStudent('2023000010');

    // Restrict the event to the CS department only.
    $event->departments()->sync([$included->department_id]);

    $result = (new MarkAllStudentsPresent)($session->fresh(), markAllAdmin());

    expect(markAllRecord($session, $officer))->toBeNull()
        ->and(markAllRecord($session, $sc))->toBeNull()
        ->and(markAllRecord($session, $inactive))->toBeNull()
        ->and(markAllRecord($session, $otherDept))->toBeNull()
        ->and(markAllRecord($session, $included)->status)->toBe(AttendanceStatus::Present)
        ->and($result['marked_present'])->toBe(1);

    // Officers/admins are still auto-Present at session end, as before.
    (new EndSession)($session);
    expect(markAllRecord($session, $officer)->status)->toBe(AttendanceStatus::Present)
        ->and(markAllRecord($session, $officer)->auto_present)->toBeTrue();
});

it('is idempotent when run twice', function () {
    $session = markAllSession();
    markAllStudent('2023000011');
    markAllStudent('2023000012');

    (new MarkAllStudentsPresent)($session, markAllAdmin());
    $second = (new MarkAllStudentsPresent)($session, markAllAdmin());

    expect($second['marked_present'])->toBe(0)
        ->and($second['already_present'])->toBe(2)
        ->and(AttendanceRecord::where('session_id', $session->id)->count())->toBe(2);
});

it('refuses a session that is not ongoing', function (SessionStatus $status) {
    $session = markAllSession(['status' => $status]);
    $student = markAllStudent('2023000013');

    expect(fn () => (new MarkAllStudentsPresent)($session, markAllAdmin()))
        ->toThrow(SessionNotAcceptingScansException::class);

    expect(markAllRecord($session, $student))->toBeNull();
})->with([SessionStatus::Scheduled, SessionStatus::Ended]);
