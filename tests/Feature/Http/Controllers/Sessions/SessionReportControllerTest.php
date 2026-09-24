<?php

use App\Domain\Enums\Role;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
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

function reportApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function reportApiSession(): AttendanceSession
{
    $department = reportApiDept('CCS');

    $creator = Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => $department->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => Role::CsgAdmin,
        ],
    );

    $event = EventModel::create(['name' => 'Test Event', 'created_by' => $creator->id]);

    $day = EventDay::create([
        'event_id' => $event->id,
        'date' => '2026-11-10',
        'day_number' => 1,
    ]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ongoing,
    ]);
}

function reportApiStaff(string $role, string $studentNumber, ?int $scAdminDeptId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => reportApiDept('CCS')->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'sc_admin_department_id' => $scAdminDeptId,
        // Clears the photo.uploaded gate, so a plain-Student fixture
        // reaches the policy check (403) under test instead of being
        // stopped early by the photo gate (423) — that gate is covered
        // separately by EnsurePhotoHasBeenUploadedTest.
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

it('rejects an unauthenticated session report request', function () {
    $session = reportApiSession();

    $this->getJson("/api/sessions/{$session->id}/report")->assertStatus(401);
});

it('rejects an officer viewing a session report', function () {
    $officer = reportApiStaff('officer', '2020200001');
    $session = reportApiSession();

    $this->actingAs($officer, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/report")
        ->assertStatus(403);
});

it('rejects a student viewing a session report', function () {
    $student = reportApiStaff('student', '2020400001');
    $session = reportApiSession();

    $this->actingAs($student, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/report")
        ->assertStatus(403);
});

it('lets a csg admin view a session report across every department', function () {
    $admin = reportApiStaff('csg_admin', '2020000002');
    $session = reportApiSession();
    Student::create([
        'student_number' => '2023000001', 'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => reportApiDept('EDUC')->id, 'username' => 'jcruz', 'password' => 'password',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/report")
        ->assertStatus(200)
        ->assertJsonPath('summary.pending', 1)
        ->assertJsonCount(1, 'students');
});

it('scopes an sc admin session report to their own department', function () {
    $bsit = reportApiDept('BSIT');
    $educ = reportApiDept('EDUC');
    $scAdmin = reportApiStaff('sc_admin', '2020300001', $bsit->id);
    $session = reportApiSession();
    Student::create([
        'student_number' => '2023000001', 'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => $bsit->id, 'username' => 'jcruz', 'password' => 'password',
    ]);
    Student::create([
        'student_number' => '2023000002', 'last_name' => 'Reyes', 'first_name' => 'Ana',
        'department_id' => $educ->id, 'username' => 'areyes', 'password' => 'password',
    ]);

    $response = $this->actingAs($scAdmin, 'sanctum')
        ->getJson("/api/sessions/{$session->id}/report")
        ->assertStatus(200)
        ->assertJsonCount(1, 'students');

    expect($response->json('students.0.student_number'))->toBe('2023000001');
});
