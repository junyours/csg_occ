<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// POST /api/sessions/{session}/mark-all-present

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-10 07:05:00', 'Asia/Manila')));
afterEach(fn () => Carbon::setTestNow());

function markAllCtrlUser(Role $role, string $number): Student
{
    return Student::firstOrCreate(
        ['student_number' => $number],
        [
            'last_name' => 'User', 'first_name' => $role->value,
            'department_id' => Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS'])->id,
            'username' => 'u'.$number, 'password' => 'password',
            'role' => $role, 'must_change_password' => false,
            'has_accepted_terms' => true,
            // EnsurePhotoHasBeenUploaded would answer 423 to a photo-less Student before the policy ran.
            'photo_path' => 'photos/test.jpg',
        ],
    );
}

function markAllCtrlSession(SessionStatus $status = SessionStatus::Ongoing): AttendanceSession
{
    $event = EventModel::create(['name' => 'Test Event', 'created_by' => markAllCtrlUser(Role::SystemAdmin, '2020000000')->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Evening,
        'check_type' => CheckType::TimeOut,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 0,
        'status' => $status,
    ]);
}

it('lets a CSG Admin and a System Admin mark everyone present', function (Role $role) {
    $session = markAllCtrlSession();
    $student = markAllCtrlUser(Role::Student, '2023100001');

    $this->actingAs(markAllCtrlUser($role, '2020000009'), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/mark-all-present")
        ->assertOk()
        ->assertJsonPath('marked_present', 1)
        ->assertJsonPath('late_converted', 0)
        ->assertJsonPath('already_present', 0);

    expect(AttendanceRecord::where('session_id', $session->id)->where('student_id', $student->id)->value('status'))
        ->toBe(AttendanceStatus::Present);
})->with([Role::CsgAdmin, Role::SystemAdmin]);

it('forbids SC Admins, officers and students', function (Role $role) {
    $session = markAllCtrlSession();
    markAllCtrlUser(Role::Student, '2023100002');

    $this->actingAs(markAllCtrlUser($role, '2020000009'), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/mark-all-present")
        ->assertForbidden();

    expect(AttendanceRecord::count())->toBe(0);
})->with([Role::ScAdmin, Role::Officer, Role::Student]);

it('requires authentication', function () {
    $session = markAllCtrlSession();

    $this->postJson("/api/sessions/{$session->id}/mark-all-present")->assertUnauthorized();
});

it('returns 409 when the session is not ongoing', function (SessionStatus $status) {
    $session = markAllCtrlSession($status);
    markAllCtrlUser(Role::Student, '2023100003');

    $this->actingAs(markAllCtrlUser(Role::CsgAdmin, '2020000009'), 'sanctum')
        ->postJson("/api/sessions/{$session->id}/mark-all-present")
        ->assertStatus(409);

    expect(AttendanceRecord::count())->toBe(0);
})->with([SessionStatus::Scheduled, SessionStatus::Ended]);
