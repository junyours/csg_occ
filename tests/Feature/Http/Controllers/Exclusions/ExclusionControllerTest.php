<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function exclusionApiDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function exclusionApiStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => exclusionApiDept('CCS')->id,
                'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Actors here authenticate and immediately hit routes behind the
        // password.changed middleware — without this every request gets a
        // 403 from that gate before it ever reaches the policy/controller.
        'must_change_password' => false,
    ]);
}

function exclusionApiStudent(string $studentNumber = '2023105413'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => exclusionApiDept()->id,
        'username' => 'jcruz'.$studentNumber,
        'password' => 'password',
    ]);
}

it('rejects an unauthenticated exclusion request', function () {
    $this->postJson('/api/exclusions', [])->assertStatus(401);
});

it('rejects exclusion creation from a non-admin role', function () {
    $officer = exclusionApiStaff('officer', '2020200001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'student_number' => $student->student_number,
        ])
        ->assertStatus(403);
});

it('lets a csg admin create an event-scoped exclusion', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'reason' => 'Academic probation',
            'student_number' => $student->student_number,
        ])
        ->assertStatus(201)
        ->assertJsonPath('student_id', $student->id)
        ->assertJsonPath('reason', 'Academic probation')
        ->assertJsonPath('status', 'active');
});

it('validates that reason is required', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'student_number' => $student->student_number,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

it('validates that either student_number or qr_token is present', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('student_number');
});

it('lets a csg admin remove an exclusion (soft — status flips to removed, row is kept)', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);

    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/exclusions/{$exclusion->id}")
        ->assertStatus(204);

    // student-exclusion-feature-plan.md §6: removal is a soft state
    // change, never a hard delete — see RemoveExclusion. The row (and
    // its audit trail) must survive.
    $exclusion->refresh();
    expect($exclusion)->not->toBeNull()
        ->and($exclusion->status->value)->toBe('removed')
        ->and($exclusion->removed_by)->toBe($admin->id)
        ->and($exclusion->removed_at)->not->toBeNull();
});

it('rejects exclusion deletion from a non-admin role', function () {
    $officer = exclusionApiStaff('officer', '2020200001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);

    $exclusion = Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => ExclusionScope::Event,
        'reason' => 'Testing exclusion',
        'created_by' => $officer->id,
    ]);

    $this->actingAs($officer, 'sanctum')
        ->deleteJson("/api/exclusions/{$exclusion->id}")
        ->assertStatus(403);
});

/**
 * student-exclusion-feature-plan.md §6a point 1 — the mid-window
 * advisory. The add always succeeds; what these cover is whether CSG is
 * *told* that it won't take effect for a session already scanned.
 */
function exclusionApiSession(EventModel $event, int $dayNumber = 1, array $overrides = []): AttendanceSession
{
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => $dayNumber],
        ['date' => '2026-11-10'],
    );

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ongoing,
    ], $overrides));
}

it('warns that an exclusion will not apply to a window the student already scanned into', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = exclusionApiSession($event);

    // The student genuinely timed in before anyone excluded them.
    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'status' => AttendanceStatus::Present,
        'scanned_at' => now(),
    ]);

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'reason' => 'Academic probation',
            'student_number' => $student->student_number,
        ])
        ->assertStatus(201)
        // The exclusion is still created — the warning is advisory, not
        // a rejection, and the row stays exactly where it belongs.
        ->assertJsonPath('student_id', $student->id)
        ->assertJsonPath('status', 'active');

    $warnings = $response->json('warnings');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('Day 1 Morning')
        ->and($warnings[0])->toContain('Time In');
});

it('returns no warnings when the student has not scanned anything yet', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    exclusionApiSession($event);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'reason' => 'Academic probation',
            'student_number' => $student->student_number,
        ])
        ->assertStatus(201)
        ->assertJsonPath('warnings', []);
});

it('does not warn about a session that had already ended before the exclusion', function () {
    $admin = exclusionApiStaff('csg_admin', '2020000001');
    $student = exclusionApiStudent();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $admin->id]);
    $session = exclusionApiSession($event, 1, ['status' => SessionStatus::Ended, 'ended_at' => now()->subHour()]);

    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'status' => AttendanceStatus::Absent,
        'scanned_at' => null,
    ]);

    // An already-closed session is outside the forward-only cascade to
    // begin with (§6a), so there is no collision to warn about — nothing
    // was ever going to overwrite that record.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/exclusions', [
            'event_id' => $event->id,
            'scope' => ExclusionScope::Event->value,
            'reason' => 'Academic probation',
            'student_number' => $student->student_number,
        ])
        ->assertStatus(201)
        ->assertJsonPath('warnings', []);
});
