<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendancePenalty;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function penaltyListApiDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function penaltyListApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => penaltyListApiDept('CCS')->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
    ]);
}

function penaltyListApiStudent(string $studentNumber, string $deptCode = 'CS'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => penaltyListApiDept($deptCode)->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
    ]);
}

function penaltyListApiPenalty(Student $creator, Student $target): AttendancePenalty
{
    $event = EventModel::create(['name' => 'Event', 'created_by' => $creator->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
    ]);

    return AttendancePenalty::create([
        'student_id' => $target->id,
        'session_id' => $session->id,
        'amount' => 25,
        'reason' => 'Absent - Time In',
    ]);
}

it('rejects an unauthenticated penalty list request', function () {
    $this->getJson('/api/penalties')->assertStatus(401);
});

it('rejects an sc admin listing penalties', function () {
    $scAdmin = penaltyListApiStaff('sc_admin', '2020000001');

    $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/penalties')
        ->assertStatus(403);
});

it('rejects an officer listing penalties', function () {
    $officer = penaltyListApiStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/penalties')
        ->assertStatus(403);
});

it('lets a csg admin list every penalty with student context', function () {
    $admin = penaltyListApiStaff('csg_admin', '2020000001');
    $student = penaltyListApiStudent('2023105413');
    $penalty = penaltyListApiPenalty($admin, $student);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/penalties')
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $penalty->id)
        ->assertJsonPath('data.0.student_number', '2023105413')
        ->assertJsonPath('data.0.is_reversed', false);
});

it('lets a system admin list every penalty', function () {
    $sysAdmin = penaltyListApiStaff('system_admin', '2020000002');
    $student = penaltyListApiStudent('2023105414');
    penaltyListApiPenalty($sysAdmin, $student);

    $this->actingAs($sysAdmin, 'sanctum')
        ->getJson('/api/penalties')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

it('filters the list by department_id', function () {
    $admin = penaltyListApiStaff('csg_admin', '2020000001');
    $ccsStudent = penaltyListApiStudent('2023100001', 'CCS');
    $bsbaStudent = penaltyListApiStudent('2023100002', 'BSBA');
    penaltyListApiPenalty($admin, $ccsStudent);
    penaltyListApiPenalty($admin, $bsbaStudent);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/penalties?department_id='.penaltyListApiDept('BSBA')->id)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student_number', '2023100002');
});

it('includes a summary alongside the paginated data', function () {
    $admin = penaltyListApiStaff('csg_admin', '2020000001');
    $ccsStudent = penaltyListApiStudent('2023100003', 'CCS');
    $bsbaStudent = penaltyListApiStudent('2023100004', 'BSBA');
    penaltyListApiPenalty($admin, $ccsStudent);
    penaltyListApiPenalty($admin, $bsbaStudent);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/penalties')
        ->assertStatus(200)
        ->assertJsonPath('summary.total', 50.0)
        ->assertJsonPath('summary.absentCount', 2)
        ->assertJsonPath('summary.lateCount', 0)
        ->assertJsonPath('summary.count', 2);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/penalties?department_id='.penaltyListApiDept('BSBA')->id)
        ->assertStatus(200)
        ->assertJsonPath('summary.total', 25.0)
        ->assertJsonPath('summary.count', 1);
});

it('validates the status filter', function () {
    $admin = penaltyListApiStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/penalties?status=bogus')
        ->assertStatus(422);
});
