<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function endEventTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function endEventTestStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => endEventTestDept()->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects unauthenticated requests to end an event', function () {
    $admin = endEventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->postJson("/api/events/{$event->id}/end")->assertStatus(401);
});

it('rejects ending an event from a non-admin role', function () {
    $officer = endEventTestStaff('officer', '2020200001');
    $admin = endEventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($officer, 'sanctum')
        ->postJson("/api/events/{$event->id}/end")
        ->assertStatus(403);
});

it('lets a csg admin end an event and cascades to its ongoing sessions', function () {
    $admin = endEventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'status' => SessionStatus::Ongoing,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/end")
        ->assertStatus(200)
        ->assertJsonPath('status', 'ended')
        ->assertJsonPath('sessions_ended', 1);

    expect($event->fresh()->status->value)->toBe('ended')
        ->and($session->fresh()->status->value)->toBe('ended');
});

it('rejects ending an event that has already been ended', function () {
    $admin = endEventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')->postJson("/api/events/{$event->id}/end")->assertStatus(200);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/end")
        ->assertStatus(409);
});
