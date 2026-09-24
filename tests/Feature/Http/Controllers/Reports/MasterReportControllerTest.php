<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Reuses the same shape of builders as BuildMasterReportTest, redeclared
// here with an "Api" suffix since Pest loads every test file's global
// function declarations into one shared namespace, and PHP doesn't allow
// declaring the same function twice (this file was previously misnamed
// and never ran, so the collision only surfaces now that it does).
function masterReportApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function masterReportApiStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => masterReportApiDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'mrstaff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function masterReportApiActiveSemester(int $createdBy): Semester
{
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $createdBy]);

    return Semester::create([
        'academic_year_id' => $academicYear->id,
        'name' => 'semester_1',
        'is_active' => true,
        'created_by' => $createdBy,
    ]);
}

function masterReportApiEvent(int $semesterId, int $createdBy, string $name = 'Intramurals 2026'): EventModel
{
    return EventModel::create(['name' => $name, 'created_by' => $createdBy, 'semester_id' => $semesterId]);
}

function masterReportApiSession(EventModel $event, int $dayNumber = 1): AttendanceSession
{
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => $dayNumber],
        ['date' => now()->addDays($dayNumber - 1)->toDateString()],
    );

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00', 'end_time' => '08:00:00', 'grace_minutes' => 15,
        'penalty_late_amount' => 10, 'penalty_absent_amount' => 25,
        'status' => SessionStatus::Ended,
    ]);
}

function masterReportApiStudent(string $studentNumber, string $deptCode = 'BSIT'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => masterReportApiDept($deptCode)->id,
        'year_level' => '1', 'section' => 'A',
        'username' => 'mruser'.$studentNumber,
        'password' => 'password',
    ]);
}

it('rejects an unauthenticated master report request', function () {
    $this->getJson('/api/reports/master')->assertStatus(401);
});

it('rejects an officer requesting the master report', function () {
    $officer = masterReportApiStaff('officer', '2020100001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/reports/master')
        ->assertStatus(403);
});

it('returns a null semester and no events when there is no active academic year at all', function () {
    $admin = masterReportApiStaff('csg_admin', '2020100002');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/reports/master')
        ->assertOk()
        ->assertJson(['academic_year' => null, 'semester' => null, 'events' => []]);
});

it('returns no events when the active academic year has no active semester', function () {
    $admin = masterReportApiStaff('csg_admin', '2020100003');
    $academicYear = AcademicYear::create(['name' => '2026-2027', 'is_active' => true, 'created_by' => $admin->id]);
    Semester::create([
        'academic_year_id' => $academicYear->id, 'name' => 'semester_1',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/reports/master')
        ->assertOk()
        ->assertJson(['semester' => null, 'events' => []])
        ->assertJsonPath('academic_year.name', '2026-2027');
});

it('lists only events in the active academic year + semester, with aggregated present/late/absent/excluded totals', function () {
    $admin = masterReportApiStaff('csg_admin', '2020100004');
    $semester = masterReportApiActiveSemester($admin->id);
    $inactiveSemester = Semester::create([
        'academic_year_id' => $semester->academic_year_id, 'name' => 'semester_2',
        'is_active' => false, 'created_by' => $admin->id,
    ]);

    $event = masterReportApiEvent($semester->id, $admin->id);
    $sessionOne = masterReportApiSession($event, 1);
    $sessionTwo = masterReportApiSession($event, 2);
    masterReportApiEvent($inactiveSemester->id, $admin->id, 'Not In Active Semester');

    $present = masterReportApiStudent('2023100001');
    $late = masterReportApiStudent('2023100002');
    $absent = masterReportApiStudent('2023100003');
    $excluded = masterReportApiStudent('2023100004');

    Exclusion::create([
        'student_id' => $excluded->id, 'event_id' => $event->id,
        'scope' => ExclusionScope::Event, 'reason' => 'Testing exclusion',
        'created_by' => $admin->id,
    ]);

    foreach ([$sessionOne, $sessionTwo] as $session) {
        AttendanceRecord::create([
            'session_id' => $session->id, 'student_id' => $present->id,
            'status' => AttendanceStatus::Present,
        ]);
        AttendanceRecord::create([
            'session_id' => $session->id, 'student_id' => $absent->id,
            'status' => AttendanceStatus::Absent,
        ]);
    }
    // Late only on the first session — present on the second.
    AttendanceRecord::create([
        'session_id' => $sessionOne->id, 'student_id' => $late->id,
        'status' => AttendanceStatus::Late,
    ]);
    AttendanceRecord::create([
        'session_id' => $sessionTwo->id, 'student_id' => $late->id,
        'status' => AttendanceStatus::Present,
    ]);
    // The excluded student never gets an attendance_records row at all
    // (mirrors EndSession::markMissingRecords) — only the Exclusion row
    // above accounts for them, once per session in scope.

    $response = $this->actingAs($admin, 'sanctum')
        ->getJson('/api/reports/master')
        ->assertOk();

    $events = $response->json('events');

    expect($events)->toHaveCount(1)
        ->and($events[0]['id'])->toBe($event->id)
        ->and($events[0]['present'])->toBe(3) // present twice + the second late-student session
        ->and($events[0]['late'])->toBe(1)
        ->and($events[0]['absent'])->toBe(2)
        ->and($events[0]['excluded'])->toBe(2); // once per session
});

it("forces an sc admin's totals to their own department, ignoring students in other departments", function () {
    $bsit = masterReportApiDept('BSIT');
    $bsba = masterReportApiDept('BSBA');
    $scAdmin = masterReportApiStaff('sc_admin', '2020100005', $bsit->id);
    $semester = masterReportApiActiveSemester($scAdmin->id);
    $event = masterReportApiEvent($semester->id, $scAdmin->id);
    $session = masterReportApiSession($event);

    $bsitStudent = masterReportApiStudent('2023100006', 'BSIT');
    $bsbaStudent = masterReportApiStudent('2023100007', 'BSBA');

    AttendanceRecord::create([
        'session_id' => $session->id, 'student_id' => $bsitStudent->id,
        'status' => AttendanceStatus::Present,
    ]);
    AttendanceRecord::create([
        'session_id' => $session->id, 'student_id' => $bsbaStudent->id,
        'status' => AttendanceStatus::Present,
    ]);

    $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/reports/master')
        ->assertOk()
        ->assertJsonPath('events.0.present', 1); // only the BSIT student counted
});
