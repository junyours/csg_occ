<?php

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

function recentScanApiDepartment(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function recentScanApiStudent(string $studentNumber, string $username, Role $role = Role::Student): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => recentScanApiDepartment()->id,
        'username' => $username,
        'password' => 'password',
        'qr_version' => 1,
        'role' => $role,
        // Clears the password.changed middleware — see the same note in
        // ReverseScanControllerTest.
        'must_change_password' => false,
        // Clears the photo.uploaded gate too, so a plain-Student fixture
        // reaches the policy check (403) under test instead of being
        // stopped early by the photo gate (423) — that gate is covered
        // separately by EnsurePhotoHasBeenUploadedTest.
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

function recentScanApiSession(array $overrides = []): AttendanceSession
{
    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => recentScanApiDepartment()->id,
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

function recentScanApiScan(AttendanceSession $session, Student $student, Student $officer, string $at): AttendanceRecord
{
    Carbon::setTestNow(Carbon::parse("2026-11-10 {$at}", 'Asia/Manila'));

    return (new ScanAttendance)(
        $session,
        QrPayload::forStudent($student->student_number, $student->qr_version)->encrypt(),
        $officer->id,
    );
}

it('rejects an unauthenticated request for the recent scans', function () {
    $session = recentScanApiSession();

    $this->getJson("/api/sessions/{$session->id}/recent-scans")->assertStatus(401);
});

it('rejects a plain student reading the recent scans', function () {
    $student = recentScanApiStudent('2023100001', 'one');
    $session = recentScanApiSession();

    $this->actingAs($student, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans")
        ->assertStatus(403);
});

it('returns only this session\'s scans, newest first, with the student payload', function () {
    $officer = recentScanApiStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScanApiSession();
    $other = recentScanApiSession([
        'check_type' => CheckType::TimeOut,
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
    ]);

    $first = recentScanApiStudent('2023100001', 'one');
    $second = recentScanApiStudent('2023100002', 'two');
    $elsewhere = recentScanApiStudent('2023100003', 'three');

    recentScanApiScan($session, $first, $officer, '07:01:00');
    recentScanApiScan($session, $second, $officer, '07:02:00');
    // Scanned into the sibling time-out session — must not appear below.
    recentScanApiScan($other, $elsewhere, $officer, '16:05:00');

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans")
        ->assertStatus(200)
        ->assertJsonCount(2)
        ->assertJsonPath('0.student_id', $second->id)
        ->assertJsonPath('1.student_id', $first->id)
        ->assertJsonPath('0.status', AttendanceStatus::Present->value)
        // Same shape the scan endpoint returns, so the scan screen maps
        // both through one parser.
        ->assertJsonPath('0.outcome', 'recorded')
        ->assertJsonPath('0.student.student_number', $second->student_number)
        ->assertJsonPath('0.student.department.code', 'CS');
});

it('stops showing a scan once it has been reversed', function () {
    $officer = recentScanApiStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScanApiSession();
    $kept = recentScanApiStudent('2023100001', 'one');
    $wrongPerson = recentScanApiStudent('2023100002', 'two');

    recentScanApiScan($session, $kept, $officer, '07:01:00');
    $wrongRecord = recentScanApiScan($session, $wrongPerson, $officer, '07:02:00');

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/sessions/{$session->id}/records/{$wrongRecord->id}/reverse")
        ->assertStatus(200);

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans")
        ->assertStatus(200)
        ->assertJsonCount(1)
        ->assertJsonPath('0.student_id', $kept->id);
});

it('honours an explicit limit', function () {
    $officer = recentScanApiStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScanApiSession();

    $first = recentScanApiStudent('2023100001', 'one');
    $second = recentScanApiStudent('2023100002', 'two');
    $third = recentScanApiStudent('2023100003', 'three');

    recentScanApiScan($session, $first, $officer, '07:01:00');
    recentScanApiScan($session, $second, $officer, '07:02:00');
    recentScanApiScan($session, $third, $officer, '07:03:00');

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans?limit=1")
        ->assertStatus(200)
        ->assertJsonCount(1)
        ->assertJsonPath('0.student_id', $third->id);
});

it('rejects a limit outside the allowed range', function () {
    $officer = recentScanApiStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScanApiSession();

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans?limit=500")
        ->assertStatus(422)
        ->assertJsonValidationErrors('limit');
});

it('returns an empty list for a session nobody has scanned into', function () {
    $officer = recentScanApiStudent('2020100001', 'officer1', Role::Officer);
    $session = recentScanApiSession();

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/recent-scans")
        ->assertStatus(200)
        ->assertExactJson([]);
});
