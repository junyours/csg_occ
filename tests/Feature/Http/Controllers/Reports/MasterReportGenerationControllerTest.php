<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function masterGenDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function masterGenStaff(string $role, string $studentNumber, ?int $scAdminDepartmentId = null): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => 'Test',
        'department_id' => masterGenDept('CCS')->id,
        'sc_admin_department_id' => $scAdminDepartmentId,
        'username' => 'mrgstaff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

function masterGenEvent(string $name, int $createdBy): EventModel
{
    return EventModel::create(['name' => $name, 'created_by' => $createdBy]);
}

function masterGenSession(EventModel $event): AttendanceSession
{
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => 1],
        ['date' => now()->toDateString()],
    );

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00', 'end_time' => '08:00:00', 'grace_minutes' => 15,
        'penalty_late_amount' => 10, 'penalty_absent_amount' => 25,
        'status' => 'ended',
    ]);
}

beforeEach(fn () => Storage::fake('local'));

it('rejects an unauthenticated master report generation request', function () {
    $this->postJson('/api/reports/master/report-generations', ['event_ids' => [1]])
        ->assertStatus(401);
});

it('rejects an officer starting a master report generation', function () {
    $officer = masterGenStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/reports/master/report-generations', ['event_ids' => [1]])
        ->assertStatus(403);
});

it('validates that event_ids is present and points at real events', function () {
    $admin = masterGenStaff('csg_admin', '2020200002');

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/reports/master/report-generations', [])
        ->assertStatus(422);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/reports/master/report-generations', ['event_ids' => [999999]])
        ->assertStatus(422);
});

it('starts a master report generation spanning every given event, in order', function () {
    $admin = masterGenStaff('csg_admin', '2020200003');
    $eventOne = masterGenEvent('Intramurals 2026', $admin->id);
    $eventTwo = masterGenEvent('Foundation Week 2026', $admin->id);
    masterGenSession($eventOne);
    masterGenSession($eventTwo);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/reports/master/report-generations', [
            'event_ids' => [$eventOne->id, $eventTwo->id],
            'format' => 'xlsx',
        ])
        ->assertStatus(202);

    $generation = ReportGeneration::findOrFail($response->json('id'));

    expect($generation->event_ids)->toBe([$eventOne->id, $eventTwo->id])
        ->and($generation->event_id)->toBe($eventOne->id)
        ->and($generation->isMaster())->toBeTrue();
});

it("forces an sc admin's master report to their own department regardless of the request body", function () {
    $bsit = masterGenDept('BSIT');
    $scAdmin = masterGenStaff('sc_admin', '2020200004', $bsit->id);
    $event = masterGenEvent('Intramurals 2026', $scAdmin->id);
    masterGenSession($event);

    $otherDept = masterGenDept('BSBA');

    $response = $this->actingAs($scAdmin, 'sanctum')
        ->postJson('/api/reports/master/report-generations', [
            'event_ids' => [$event->id],
            'department_id' => $otherDept->id,
        ])
        ->assertStatus(202);

    $generation = ReportGeneration::findOrFail($response->json('id'));

    expect($generation->department_id)->toBe($bsit->id);
});
