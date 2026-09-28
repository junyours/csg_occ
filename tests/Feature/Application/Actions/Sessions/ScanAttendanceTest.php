<?php

use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\SessionNotAcceptingScansException;
use App\Domain\Exceptions\SessionScanTooEarlyException;
use App\Domain\Exceptions\StaleQrCodeException;
use App\Domain\Exceptions\StudentDepartmentNotIncludedException;
use App\Domain\Exceptions\StudentExcludedException;
use App\Domain\Exceptions\StudentInactiveException;
use App\Domain\Exceptions\StudentNotEligibleForAttendanceException;
use App\Domain\ValueObjects\QrPayload;
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

// The session fixture below is anchored to a fixed calendar date
// (2026-11-10), so every test needs the clock frozen inside that
// session's window rather than relying on real wall-clock time.
beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function makeDay(?EventModel $event = null): EventDay
{
    // firstOrCreate throughout: several tests build a sibling session on
    // "the same day", which means reusing the same department/event/day
    // row rather than colliding on the department's unique `code`.
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => $department->id,
            'username' => 'csgadmin', 'password' => 'password',
        ],
    );

    $event ??= EventModel::firstOrCreate(
        ['name' => 'Test Event'],
        ['created_by' => $creator->id],
    );

    return EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => 1],
        ['date' => '2026-11-10'],
    );
}

function makeSession(array $overrides = [], ?EventModel $event = null): AttendanceSession
{
    return AttendanceSession::create(array_merge([
        'event_day_id' => makeDay($event)->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ongoing,
    ], $overrides));
}

function makeStudent(int $qrVersion = 1, ?string $departmentCode = null): Student
{
    $department = $departmentCode
        ? Department::firstOrCreate(['code' => $departmentCode], ['name' => $departmentCode])
        : Department::create(['name' => 'CS', 'code' => 'CS']);

    return Student::create([
        'student_number' => '2023105413',
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'username' => 'jcruz',
        'password' => 'password',
        'qr_version' => $qrVersion,
    ]);
}

it('records a scan as Present within the grace window', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token);

    expect($record->student_id)->toBe($student->id)
        ->and($record->status)->toBe(AttendanceStatus::Present)
        ->and($record->scanned_at)->not->toBeNull();
});

it('is idempotent on a second scan and does not throw', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $action = new ScanAttendance;
    $first = $action($session, $token);
    $second = $action($session, $token);

    expect(AttendanceRecord::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->scanned_at)->toEqual($first->scanned_at);
});

it('records the time-out sibling session independently of the time-in scan', function () {
    $timeIn = makeSession();
    $timeOut = makeSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    (new ScanAttendance)($timeIn, $token);
    Carbon::setTestNow(Carbon::parse('2026-11-10 16:05:00', 'Asia/Manila'));
    (new ScanAttendance)($timeOut, $token);

    expect(AttendanceRecord::where('session_id', $timeIn->id)->count())->toBe(1)
        ->and(AttendanceRecord::where('session_id', $timeOut->id)->count())->toBe(1);
});

it('rejects a scan from a QR token with a stale qr_version', function () {
    $session = makeSession();
    $student = makeStudent(qrVersion: 2);
    $staleToken = QrPayload::forStudent($student->student_number, 1)->encrypt(); // version 1, student is now on 2

    (new ScanAttendance)($session, $staleToken);
})->throws(StaleQrCodeException::class);

it('rejects a scan when the session is not ongoing', function () {
    $session = makeSession(['status' => SessionStatus::Scheduled]);
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    (new ScanAttendance)($session, $token);
})->throws(SessionNotAcceptingScansException::class);

// Belt-and-suspenders (SessionScanTooEarlyException): StartSession already
// refuses to move a session to Ongoing before its scheduled start time, so
// this situation shouldn't arise through normal use — but ScanAttendance
// checks the server clock again anyway, independently, so a scan can never
// be recorded ahead of a session's own window under any circumstance,
// including a scanning device's clock being wound back (which has no
// bearing here: Carbon::now() below is this test's frozen *server* clock,
// never anything the scanning device would report).
it('rejects a scan against an ongoing session before its scheduled start time', function () {
    // Ongoing despite start_time being in the future relative to "now" —
    // only reachable today via direct model state (e.g. a manual DB edit
    // or a bug), exactly the scenario this second, independent check
    // exists for.
    $session = makeSession(['status' => SessionStatus::Ongoing, 'start_time' => '09:00:00', 'end_time' => '10:00:00']);
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    // Frozen "now" (07:05, from beforeEach) is well before the session's
    // 09:00 start.
    (new ScanAttendance)($session, $token);
})->throws(SessionScanTooEarlyException::class);

it('allows a scan against an ongoing session once its scheduled start time arrives', function () {
    $session = makeSession(['status' => SessionStatus::Ongoing, 'start_time' => '07:00:00', 'end_time' => '08:00:00']);
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    // beforeEach freezes "now" at 07:05 — after this session's 07:00 start.
    $record = (new ScanAttendance)($session, $token);

    expect($record->status)->toBe(AttendanceStatus::Present);
});

it('classifies a scan outside the grace window as Late', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    Carbon::setTestNow(Carbon::parse('2026-11-10 09:00:00', 'Asia/Manila'));

    $record = (new ScanAttendance)($session, $token);

    expect($record->status)->toBe(AttendanceStatus::Late);
});

it('rejects a scan from a student excluded from the whole event', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $session->eventDay->event_id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $student->id,
    ]);

    (new ScanAttendance)($session, $token);
})->throws(StudentExcludedException::class);

it('rejects a scan from a student excluded from this specific day+window', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $session->eventDay->event_id,
        'scope' => ExclusionScope::Window,
        'event_day_id' => $session->event_day_id,
        'window_type' => $session->window_type,
        'reason' => 'Testing exclusion',
        'created_by' => $student->id,
    ]);

    (new ScanAttendance)($session, $token);
})->throws(StudentExcludedException::class);

it('still allows a scan from a student excluded from a different window on the same day', function () {
    $session = makeSession(); // Morning
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $session->eventDay->event_id,
        'scope' => ExclusionScope::Window,
        'event_day_id' => $session->event_day_id,
        'window_type' => WindowType::Evening,
        'reason' => 'Testing exclusion',
        'created_by' => $student->id,
    ]);

    $record = (new ScanAttendance)($session, $token);

    expect($record->status)->toBe(AttendanceStatus::Present);
});

it('resolves gracefully instead of throwing when two officers scan the same badge concurrently', function () {
    // Simulates the race two simultaneous scanners can hit: both pass the
    // $existing check as null before either INSERT commits. A model event
    // listener inserts the "other officer's" row right before this action's
    // own create() reaches the database, forcing the unique constraint on
    // (session_id, student_id) to reject it exactly as it would under real
    // concurrency.
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    AttendanceRecord::creating(function (AttendanceRecord $record) use ($session, $student) {
        if (AttendanceRecord::where('session_id', $session->id)->where('student_id', $student->id)->exists()) {
            return; // the "other officer's" row already landed — let this one hit the unique constraint
        }

        AttendanceRecord::withoutEvents(fn () => AttendanceRecord::create([
            'session_id' => $session->id,
            'student_id' => $student->id,
            'scanned_at' => now(),
            'status' => AttendanceStatus::Present,
            'scanned_by' => null,
        ]));
    });

    $record = (new ScanAttendance)($session, $token);

    expect($record)->not->toBeNull()
        ->and(AttendanceRecord::where('session_id', $session->id)->where('student_id', $student->id)->count())->toBe(1);

    AttendanceRecord::flushEventListeners();
});

it('rejects a scan from a student whose department is not included in the event scope', function () {
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS'])->id,
            'username' => 'csgadmin', 'password' => 'password',
        ],
    );
    $bsit = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    $bsba = Department::firstOrCreate(['code' => 'BSBA'], ['name' => 'BSBA']);

    $scopedEvent = EventModel::create(['name' => 'BSIT-only Event', 'created_by' => $creator->id]);
    $scopedEvent->departments()->sync([$bsit->id]);

    $session = makeSession([], $scopedEvent);
    $student = makeStudent(departmentCode: $bsba->code); // not part of the event's scope
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    (new ScanAttendance)($session, $token);
})->throws(StudentDepartmentNotIncludedException::class);

it('allows a scan from a student whose department is included in the event scope', function () {
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS'])->id,
            'username' => 'csgadmin', 'password' => 'password',
        ],
    );
    $bsit = Department::firstOrCreate(['code' => 'BSIT'], ['name' => 'BSIT']);
    $bed = Department::firstOrCreate(['code' => 'BED'], ['name' => 'BEd']);

    $scopedEvent = EventModel::create(['name' => 'BSIT + BEd Event', 'created_by' => $creator->id]);
    $scopedEvent->departments()->sync([$bsit->id, $bed->id]);

    $session = makeSession([], $scopedEvent);
    $student = makeStudent(departmentCode: $bed->code); // part of the event's scope
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token);

    expect($record->student_id)->toBe($student->id)
        ->and($record->status)->toBe(AttendanceStatus::Present);
});

it('allows a scan from any department when the event has no department restriction', function () {
    // An event created before department scoping existed (or one where
    // every department was checked at creation) has an empty
    // event_departments pivot — unrestricted, per EventModel::includedDepartmentIds.
    $session = makeSession(); // "Test Event" — no departments synced
    $student = makeStudent(departmentCode: 'BSBA');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token);

    expect($record->status)->toBe(AttendanceStatus::Present);
});

it('rejects a scan from an inactive student, writing nothing', function () {
    $session = makeSession();
    $student = makeStudent();
    $student->update(['status' => 'inactive']);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    try {
        (new ScanAttendance)($session, $token);
        $this->fail('Expected StudentInactiveException.');
    } catch (StudentInactiveException) {
        expect(AttendanceRecord::count())->toBe(0);
    }
});

it('still returns the existing record when a student was deactivated after scanning', function () {
    $session = makeSession();
    $student = makeStudent();
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $first = (new ScanAttendance)($session, $token);
    $student->update(['status' => 'inactive']);

    expect((new ScanAttendance)($session, $token)->id)->toBe($first->id)
        ->and(AttendanceRecord::count())->toBe(1);
});

it('refuses a staff account with a message saying attendance is automatic', function () {
    $session = makeSession();
    $student = makeStudent();
    $student->update(['role' => 'officer']);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    expect(fn () => (new ScanAttendance)($session, $token))
        ->toThrow(StudentNotEligibleForAttendanceException::class, 'recorded automatically');
});

it('refuses a System Admin with its own message', function () {
    $session = makeSession();
    $student = makeStudent();
    $student->update(['role' => 'system_admin']);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    expect(fn () => (new ScanAttendance)($session, $token))
        ->toThrow(StudentNotEligibleForAttendanceException::class, 'System Admin');
});

it('freezes the enrollment snapshot onto the record', function () {
    $session = makeSession();
    $student = makeStudent();
    $student->update(['major' => 'FM', 'section' => '2A', 'year_level' => '2']);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $record = (new ScanAttendance)($session, $token);
    $student->update(['section' => '3B', 'year_level' => '3']);

    expect($record->fresh())
        ->department_id->toBe($student->department_id)
        ->major->toBe('FM')
        ->section->toBe('2A')
        ->year_level->toBe('2')
        ->auto_present->toBeFalse();
});
