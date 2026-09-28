<?php

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

// POST /api/sessions/{session}/start — controller-level coverage for the
// server-clock gate added to StartSession (SessionStartTooEarlyException).
// The action-level tests in StartSessionTest.php cover the gate's logic
// directly; these confirm the same rule holds through the real HTTP route,
// with the real 409 status code the frontend's toast relies on.

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 06:59:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function startCtrlDept(): Department
{
    return Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);
}

function startCtrlAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => startCtrlDept()->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin', 'must_change_password' => false,
        ],
    );
}

function startCtrlSession(array $overrides = []): AttendanceSession
{
    $event = EventModel::create(['name' => 'Test Event', 'created_by' => startCtrlAdmin()->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Scheduled,
    ], $overrides));
}

it('rejects starting a session one minute before its scheduled start time', function () {
    $session = startCtrlSession(); // starts_time 07:00:00; frozen "now" is 06:59:00

    $this->actingAs(startCtrlAdmin(), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/start")
        ->assertStatus(409)
        ->assertJsonPath('message', "This session cannot be started yet — it is scheduled to start at 7:00 AM.");

    expect($session->fresh()->status)->toBe(SessionStatus::Scheduled);
});

it('allows starting a session once its scheduled start time arrives', function () {
    $session = startCtrlSession();

    Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'Asia/Manila'));

    $this->actingAs(startCtrlAdmin(), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/start")
        ->assertStatus(200)
        ->assertJsonPath('status', 'ongoing');
});

it('ignores any client-supplied time and relies solely on the server clock', function () {
    $session = startCtrlSession();

    // A spoofed "current time" the client might try to smuggle in — the
    // controller/action never reads anything like this from the request,
    // so it has no effect. The real (frozen) server clock is still 06:59,
    // one minute before the session opens.
    $this->actingAs(startCtrlAdmin(), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/start", [
            'now' => '2026-11-10T07:30:00+08:00',
            'client_time' => '2026-11-10T07:30:00+08:00',
        ])
        ->assertStatus(409);
});
