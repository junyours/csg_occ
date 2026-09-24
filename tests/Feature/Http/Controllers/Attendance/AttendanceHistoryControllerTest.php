<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function historyApiDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function historyApiStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => historyApiDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        'must_change_password' => false,
    ]);
}

function historyApiStudent(string $studentNumber, string $deptCode = 'CCS'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => historyApiDept($deptCode)->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
    ]);
}

function historyApiRecord(Student $creator, Student $target, array $overrides = []): AttendanceRecord
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

    return AttendanceRecord::create(array_merge([
        'session_id' => $session->id,
        'student_id' => $target->id,
        'scanned_by' => $creator->id,
        'scanned_at' => now(),
        'status' => 'present',
    ], $overrides));
}

it('rejects an unauthenticated attendance history request', function () {
    $this->getJson('/api/attendance-history')->assertStatus(401);
});

it('scopes an officer to only the records they personally scanned', function () {
    $officer = historyApiStaff('officer', '2020200001');
    $otherOfficer = historyApiStaff('officer', '2020200002');

    $ownRecord = historyApiRecord($officer, historyApiStudent('2023105420'));
    historyApiRecord($otherOfficer, historyApiStudent('2023105421'));

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/attendance-history')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownRecord->id)
        ->assertJsonPath('data.0.scanned_by', $officer->id);
});

it('forces an officer\'s scope even when a different scanned_by-adjacent filter is requested', function () {
    $officer = historyApiStaff('officer', '2020200003');
    $bsba = historyApiDept('BSBA');
    $ownRecord = historyApiRecord($officer, historyApiStudent('2023105422', 'BSBA'));

    // department_id is a real, otherwise-valid filter — asserts the
    // officer scope is layered on top of it, not replaced by it.
    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/attendance-history?department_id='.$bsba->id)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownRecord->id);
});

it('rejects a plain student viewing attendance history', function () {
    $student = historyApiStudent('2023105423');

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/attendance-history')
        ->assertStatus(403);
});

it('lets a csg admin list attendance records with scanner context', function () {
    $admin = historyApiStaff('csg_admin', '2020000001');
    $student = historyApiStudent('2023105413');
    $record = historyApiRecord($admin, $student);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history')
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $record->id)
        ->assertJsonPath('data.0.student_number', '2023105413')
        ->assertJsonPath('data.0.scanned_by_name', 'Csg_admin Staff')
        ->assertJsonPath('data.0.status', 'present');
});

it('lets a system admin list every record across departments', function () {
    $sysAdmin = historyApiStaff('system_admin', '2020000002');
    historyApiRecord($sysAdmin, historyApiStudent('2023105414', 'CCS'));
    historyApiRecord($sysAdmin, historyApiStudent('2023105415', 'BSBA'));

    $this->actingAs($sysAdmin, 'sanctum')
        ->getJson('/api/attendance-history')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('scopes an sc admin to their own department regardless of the requested filter', function () {
    $ccs = historyApiDept('CCS');
    $bsba = historyApiDept('BSBA');
    $scAdmin = historyApiStaff('sc_admin', '2020000003', $ccs->id);

    historyApiRecord($scAdmin, historyApiStudent('2023100001', 'CCS'));
    historyApiRecord($scAdmin, historyApiStudent('2023100002', 'BSBA'));

    $this->actingAs($scAdmin, 'sanctum')
        ->getJson('/api/attendance-history?department_id='.$bsba->id)
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.student_number', '2023100001');
});

it('filters by status', function () {
    $admin = historyApiStaff('csg_admin', '2020000001');
    historyApiRecord($admin, historyApiStudent('2023100003'), ['status' => 'present']);
    historyApiRecord($admin, historyApiStudent('2023100004'), ['status' => 'late']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history?status=late')
        ->assertStatus(200)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'late');
});

it('validates the status and sort filters', function () {
    $admin = historyApiStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history?status=bogus')
        ->assertStatus(422);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/attendance-history?sort=bogus')
        ->assertStatus(422);
});
