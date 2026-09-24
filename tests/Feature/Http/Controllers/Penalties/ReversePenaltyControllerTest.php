<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendancePenalty;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\PenaltyReversal;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function reversePenaltyApiDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function reversePenaltyApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => reversePenaltyApiDept('CCS')->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Same reasoning as ExclusionControllerTest: bypass the
        // password.changed gate so requests reach the policy/controller.
        'must_change_password' => false,
    ]);
}

function reversePenaltyApiStudent(string $studentNumber = '2023105413'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => reversePenaltyApiDept()->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
    ]);
}

function reversePenaltyApiPenalty(Student $creator, Student $target): AttendancePenalty
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

it('rejects an unauthenticated penalty reversal request', function () {
    $admin = reversePenaltyApiStaff('csg_admin', '2020000001');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($admin, $student);

    $this->patchJson("/api/penalties/{$penalty->id}/reverse", ['reason' => 'Excused'])
        ->assertStatus(401);
});

it('rejects penalty reversal from a non-admin role', function () {
    $officer = reversePenaltyApiStaff('officer', '2020200001');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($officer, $student);

    $this->actingAs($officer, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", ['reason' => 'Excused'])
        ->assertStatus(403);

    expect($penalty->fresh()->is_reversed)->toBeFalse();
});

it('lets a csg admin reverse a penalty and records who/why', function () {
    $admin = reversePenaltyApiStaff('csg_admin', '2020000001');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($admin, $student);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", [
            'reason' => 'Documented excuse letter approved',
        ])
        ->assertStatus(200)
        ->assertJsonPath('is_reversed', true)
        ->assertJsonPath('reversed_by', $admin->id)
        ->assertJsonPath('reversal_reason', 'Documented excuse letter approved');

    expect(PenaltyReversal::query()->count())->toBe(1)
        ->and(PenaltyReversal::first()->reversed_by)->toBe($admin->id);
});

it('lets a system admin reverse a penalty', function () {
    $sysAdmin = reversePenaltyApiStaff('system_admin', '2020000002');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($sysAdmin, $student);

    $this->actingAs($sysAdmin, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", ['reason' => 'Excused'])
        ->assertStatus(200);
});

it('validates that a reason is required', function () {
    $admin = reversePenaltyApiStaff('csg_admin', '2020000001');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($admin, $student);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('rejects reversing the same penalty twice', function () {
    $admin = reversePenaltyApiStaff('csg_admin', '2020000001');
    $student = reversePenaltyApiStudent();
    $penalty = reversePenaltyApiPenalty($admin, $student);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", ['reason' => 'First excuse'])
        ->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/penalties/{$penalty->id}/reverse", ['reason' => 'Second attempt'])
        ->assertStatus(409);

    expect(PenaltyReversal::query()->count())->toBe(1);
});

it('returns 404 for a nonexistent penalty', function () {
    $admin = reversePenaltyApiStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/penalties/999999/reverse', ['reason' => 'Excused'])
        ->assertStatus(404);
});
