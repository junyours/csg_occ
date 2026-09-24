<?php

use App\Application\Actions\Sessions\ReverseAttendanceRecord;
use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordReversal;
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

function revScanApiDepartment(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function revScanApiStudent(string $studentNumber, string $username, Role $role = Role::Student): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => revScanApiDepartment()->id,
        'username' => $username,
        'password' => 'password',
        'qr_version' => 1,
        'role' => $role,
        // Every route under /api here sits behind the password.changed
        // middleware, which 403s any account still carrying the default
        // must_change_password = true. Cleared on the fixture so requests
        // actually reach the policy and controller under test.
        'must_change_password' => false,
        // Clears the photo.uploaded gate too, so a plain-Student fixture
        // reaches the policy check (403) under test instead of being
        // stopped early by the photo gate (423) — that gate is covered
        // separately by EnsurePhotoHasBeenUploadedTest.
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

function revScanApiSession(array $overrides = []): AttendanceSession
{
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => revScanApiDepartment()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'must_change_password' => false,
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

/** Put a real scan on the board so there's something to reverse. */
function revScanApiRecord(AttendanceSession $session, Student $student, Student $officer): AttendanceRecord
{
    return (new ScanAttendance)(
        $session,
        QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt(),
        $officer->id,
    );
}

it('rejects an unauthenticated reversal', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(401);
});

it('rejects a plain student attempting to reverse a scan', function () {
    // Same role split as scanning itself: the person being scanned never
    // gets to undo their own record.
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->actingAs($student, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(403);

    expect(AttendanceRecord::count())->toBe(1);
});

it('lets the scanning officer reverse a scan back to pending', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse", [
            'reason' => 'Presented by another student',
        ])
        ->assertStatus(200)
        // record_id comes back so the scan screen knows which row to
        // drop out of its list — it refers to a row that no longer exists.
        ->assertJsonPath('record_id', $record->id)
        ->assertJsonPath('student_id', $student->id)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('reason', 'Presented by another student')
        ->assertJsonPath('reversed_by', $officer->id);

    expect(AttendanceRecord::count())->toBe(0)
        ->and(AttendanceRecordReversal::count())->toBe(1);
});

it('lets a csg admin reverse a scan an officer made', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $admin = revScanApiStudent('2020100002', 'csg2', Role::CsgAdmin);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(200)
        ->assertJsonPath('reversed_by', $admin->id);

    // The audit row keeps both halves apart: who made the wrong scan,
    // and who undid it.
    $reversal = AttendanceRecordReversal::firstOrFail();

    expect($reversal->scanned_by)->toBe($officer->id)
        ->and($reversal->reversed_by)->toBe($admin->id)
        ->and($reversal->reason)->toBe(ReverseAttendanceRecord::DEFAULT_REASON);
});

it('returns 404 for a record that belongs to a different session', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $timeIn = revScanApiSession();
    $timeOut = revScanApiSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);
    $record = revScanApiRecord($timeIn, $student, $officer);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$timeOut->id}/records/{$record->id}/reverse")
        ->assertStatus(404);

    expect(AttendanceRecord::count())->toBe(1)
        ->and(AttendanceRecordReversal::count())->toBe(0);
});

it('returns 409 once the session has ended', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $session->update(['status' => SessionStatus::Ended]);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(409);

    expect(AttendanceRecord::count())->toBe(1);
});

it('returns 404 for a record that was already reversed', function () {
    // Two officers on two phones tapping reverse on the same row: the
    // second one finds nothing to bind to.
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(200);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(404);

    expect(AttendanceRecordReversal::count())->toBe(1);
});

it('rejects a reason that is too short to mean anything', function () {
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse", ['reason' => 'x'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect(AttendanceRecord::count())->toBe(1);
});

it('frees the slot so the real owner can scan in afterwards', function () {
    // End to end through HTTP: reverse, then the genuine badge holder
    // walks up and scans, and the scan is recorded rather than bouncing
    // off the unique session+student index.
    $officer = revScanApiStudent('2020100001', 'officer1', Role::Officer);
    $student = revScanApiStudent('2023100001', 'one');
    $session = revScanApiSession();
    $record = revScanApiRecord($session, $student, $officer);
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$record->id}/reverse")
        ->assertStatus(200);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(200)
        // "recorded", not "duplicate": as far as the system is concerned
        // this student had never checked in.
        ->assertJsonPath('outcome', 'recorded')
        ->assertJsonPath('student_id', $student->id);
});
