<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use App\Domain\ValueObjects\QrPayload;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function apiDepartment(string $code = 'CS'): Department
{
    // firstOrCreate, not create: tests in this file often need two distinct
    // students, and both calls should be free to reuse the same department
    // instead of colliding on the unique `code` column.
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function apiSession(array $overrides = []): AttendanceSession
{
    $department = apiDepartment('CCS');

    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => $department->id,
            'username' => 'csgadmin', 'password' => 'password',
        ],
    );

    $event = EventModel::firstOrCreate(
        ['name' => 'Test Event'],
        ['created_by' => $creator->id],
    );

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

function apiStudent(int $qrVersion = 1, string $studentNumber = '2023105413', string $username = 'jcruz', Role $role = Role::Student): Student
{
    $department = apiDepartment('CS');

    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $department->id,
        'username' => $username,
        'password' => 'password',
        'qr_version' => $qrVersion,
        'role' => $role,
        // Clears the photo.uploaded gate, so a plain-Student "notStaff"
        // fixture reaches AttendanceSessionPolicy::scan (403) under test
        // instead of being stopped early by the photo gate (423) — that
        // gate is covered separately by EnsurePhotoHasBeenUploadedTest.
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

// Every "staff" account in this file needs to actually be a scan-eligible
// role (see AttendanceSessionPolicy::scan) — apiStudent() defaults to a
// plain Student, which is deliberately *not* scan-eligible, so anything
// standing in as the officer at the gate goes through this helper instead.
function apiOfficer(string $studentNumber = '2020100001', string $username = 'staffuser'): Student
{
    return apiStudent(studentNumber: $studentNumber, username: $username, role: Role::Officer);
}

it('rejects an unauthenticated scan request', function () {
    $session = apiSession();

    $this->postJson("/api/sessions/{$session->id}/scan", ['token' => 'whatever'])
        ->assertStatus(401);
});

it('rejects a plain student attempting to scan', function () {
    // A plain Student is the one role deliberately left out of
    // AttendanceSessionPolicy::scan — they're the thing being scanned,
    // not the one holding the scanner. Regression test for the
    // previously-open `ScanAttendanceRequest::authorize()` gap.
    $notStaff = apiStudent(studentNumber: '2020100001', username: 'staffuser', role: Role::Student);
    $student = apiStudent(studentNumber: '2023105413', username: 'jcruz');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    $session = apiSession();

    $this->actingAs($notStaff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(403);
});

it('records a scan for an authenticated staff member', function () {
    $staff = apiOfficer();
    $student = apiStudent(studentNumber: '2023105413', username: 'jcruz');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    $session = apiSession();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(200)
        ->assertJsonPath('status', AttendanceStatus::Present->value)
        ->assertJsonPath('student_id', $student->id)
        // First-ever scan for this student/session: a fresh record, and
        // the student relation rides along so the scanner UI can show a
        // name/photo/section without a second request.
        ->assertJsonPath('outcome', 'recorded')
        ->assertJsonPath('student.id', $student->id)
        ->assertJsonPath('student.student_number', $student->student_number);
});

it('flags a repeat scan of the same badge as a duplicate, not a fresh recording', function () {
    $staff = apiOfficer();
    $student = apiStudent(studentNumber: '2023105413', username: 'jcruz');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    $session = apiSession();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(200)
        ->assertJsonPath('outcome', 'recorded');

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(200)
        ->assertJsonPath('outcome', 'duplicate');
});

it('records a time-out sibling session independently of the time-in scan', function () {
    $staff = apiOfficer();
    $student = apiStudent(studentNumber: '2023105413', username: 'jcruz');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    $timeIn = apiSession();
    $timeOut = apiSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$timeIn->id}/scan", ['token' => $token])
        ->assertStatus(200)
        ->assertJsonPath('outcome', 'recorded');

    Carbon::setTestNow(Carbon::parse('2026-11-10 16:05:00', 'Asia/Manila'));

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$timeOut->id}/scan", ['token' => $token])
        ->assertStatus(200)
        ->assertJsonPath('outcome', 'recorded');
});

it('returns 422 for a malformed token', function () {
    $staff = apiOfficer();
    $session = apiSession();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => 'not-a-real-token'])
        ->assertStatus(422);
});

it('returns 409 for a stale qr_version', function () {
    $staff = apiOfficer();
    $student = apiStudent(qrVersion: 2, studentNumber: '2023105413', username: 'jcruz');
    $staleToken = QrPayload::forStudent($student->student_number, 1)->encrypt();
    $session = apiSession();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $staleToken])
        ->assertStatus(409);
});

it('returns 409 when the session is not ongoing', function () {
    $staff = apiOfficer();
    $student = apiStudent(studentNumber: '2023105413', username: 'jcruz');
    $token = QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt();
    $session = apiSession(['status' => SessionStatus::Scheduled]);

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", ['token' => $token])
        ->assertStatus(409);
});

it('validates that a token is present', function () {
    $staff = apiOfficer();
    $session = apiSession();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/scan", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('token');
});
