<?php

use App\Domain\Enums\CheckType;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// event-day-window-edit-delete-plan.md §6: DELETE
// /event-days/{eventDay}/windows/{windowType} — controller-level
// coverage for DeleteWindow.

function dwcoDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function dwcoAdmin(string $studentNumber = '2020000001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => dwcoDept()->id,
            'username' => 'csgadmin'.$studentNumber, 'password' => 'password',
            'role' => 'csg_admin', 'must_change_password' => false,
        ],
    );
}

function dwcoOfficer(string $studentNumber = '2020200001'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Officer', 'first_name' => 'Test',
            'department_id' => dwcoDept()->id,
            'username' => 'officer'.$studentNumber, 'password' => 'password',
            'role' => 'officer', 'must_change_password' => false,
        ],
    );
}

function dwcoStudent(string $studentNumber = '2023105413'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => dwcoDept()->id,
            'username' => 'jcruz'.$studentNumber, 'password' => 'password',
            'qr_version' => 1,
        ],
    );
}

function dwcoEvent(array $overrides = []): EventModel
{
    return EventModel::create(array_merge(['name' => 'Test Event', 'created_by' => dwcoAdmin()->id], $overrides));
}

function dwcoDay(EventModel $event, int $dayNumber = 1, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function dwcoSession(EventDay $day, array $overrides = []): AttendanceSession
{
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

it('rejects an unauthenticated window deletion', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day);

    $this->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(401);
});

it('rejects a window deletion from a non-admin role', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day);

    $this->actingAs(dwcoOfficer(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(403);
});

it('lets a csg admin delete every still-scheduled check of a window', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day, ['check_type' => CheckType::TimeIn]);
    dwcoSession($day, ['check_type' => CheckType::TimeOut]);

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(200)
        ->assertJsonPath('window_type', 'morning')
        ->assertJsonPath('sessions_deleted', 2);

    expect(AttendanceSession::where('event_day_id', $day->id)->count())->toBe(0);
});

it('deletes a window that only ever had a single check configured', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day, ['check_type' => CheckType::TimeIn]);

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(200)
        ->assertJsonPath('sessions_deleted', 1);
});

it('returns 409 deleting a window with even one started check', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day, ['check_type' => CheckType::TimeIn, 'status' => SessionStatus::Ongoing]);
    dwcoSession($day, ['check_type' => CheckType::TimeOut]);

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(409);

    expect(AttendanceSession::where('event_day_id', $day->id)->count())->toBe(2);
});

it('returns 409 deleting a window whose parent event has already ended', function () {
    $event = dwcoEvent(['status' => EventStatus::Ended]);
    $day = dwcoDay($event);
    dwcoSession($day);

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(409);
});

it('returns 422 deleting a window that has no sessions on that day', function () {
    $day = dwcoDay(dwcoEvent());

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(422);
});

it('returns 404 deleting a window on a nonexistent day', function () {
    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson('/api/event-days/999999/windows/morning')
        ->assertStatus(404);
});

it('returns 404 for an invalid window_type route segment', function () {
    $day = dwcoDay(dwcoEvent());
    dwcoSession($day);

    $this->actingAs(dwcoAdmin(), 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/not-a-real-window")
        ->assertStatus(404);
});

it('cascades window-scope exclusion soft-removal through the HTTP endpoint', function () {
    $admin = dwcoAdmin();
    $student = dwcoStudent();
    $event = dwcoEvent();
    $day = dwcoDay($event);
    dwcoSession($day, ['check_type' => CheckType::TimeIn]);
    dwcoSession($day, ['check_type' => CheckType::TimeOut]);
    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'scope' => ExclusionScope::Window,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(200)
        ->assertJsonPath('exclusions_removed', 1);

    expect($exclusion->refresh()->status)->toBe(ExclusionStatus::Removed);
});

it('does not touch an exclusion for a different window_type on the same day', function () {
    $admin = dwcoAdmin();
    $student = dwcoStudent();
    $event = dwcoEvent();
    $day = dwcoDay($event);
    dwcoSession($day, ['window_type' => WindowType::Morning]);
    $afternoonExclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'event_day_id' => $day->id,
        'window_type' => WindowType::Afternoon,
        'scope' => ExclusionScope::Window,
        'reason' => 'Testing',
        'status' => ExclusionStatus::Active,
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/event-days/{$day->id}/windows/morning")
        ->assertStatus(200)
        ->assertJsonPath('exclusions_removed', 0);

    expect($afternoonExclusion->refresh()->status)->toBe(ExclusionStatus::Active);
});
