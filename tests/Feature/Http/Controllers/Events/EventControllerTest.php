<?php

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EventModel;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function eventTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function eventTestSemester(Student $createdBy): Semester
{
    $academicYear = AcademicYear::firstOrCreate(
        ['name' => '2026-2027'],
        ['is_active' => true, 'created_by' => $createdBy->id],
    );

    return Semester::firstOrCreate(
        ['academic_year_id' => $academicYear->id, 'name' => 'semester_1'],
        ['is_active' => true, 'created_by' => $createdBy->id],
    );
}

function eventTestStaff(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Staff', 'first_name' => ucfirst($role),
        'department_id' => eventTestDept()->id,
        'username' => 'staff'.$studentNumber,
        'password' => 'password',
        'role' => $role,
    ]);
}

it('rejects unauthenticated event listing and creation', function () {
    $this->getJson('/api/events')->assertStatus(401);
    $this->postJson('/api/events', [])->assertStatus(401);
});

it('lets any authenticated role list events', function () {
    $officer = eventTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->getJson('/api/events')
        ->assertStatus(200);
});

it('rejects event creation from a non-admin role', function () {
    $officer = eventTestStaff('officer', '2020200001');

    $this->actingAs($officer, 'sanctum')
        ->postJson('/api/events', ['name' => 'Intramurals 2026'])
        ->assertStatus(403);
});

it('lets a csg admin create an event, auto-attached to the active semester', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $semester = eventTestSemester($admin);
    $department = eventTestDept();

    // No semester_id in the request — the person creating the event
    // never picks one, it's resolved from whatever's active.
    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', [
            'name' => 'Intramurals 2026',
            'description' => 'Sports fest',
            'department_ids' => [$department->id],
        ])
        ->assertStatus(201)
        ->assertJsonPath('name', 'Intramurals 2026')
        ->assertJsonPath('semester_id', $semester->id);
});

it('rejects event creation when there is no active semester', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $department = eventTestDept();

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', ['name' => 'Intramurals 2026', 'department_ids' => [$department->id]])
        ->assertStatus(422);
});

it('rejects event creation with no department_ids at all', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    eventTestSemester($admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', ['name' => 'Intramurals 2026'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department_ids');
});

it('rejects event creation with an empty department_ids array', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    eventTestSemester($admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', ['name' => 'Intramurals 2026', 'department_ids' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department_ids');
});

it('rejects event creation with a department_ids entry that does not exist', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    eventTestSemester($admin);

    $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', ['name' => 'Intramurals 2026', 'department_ids' => [999999]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('department_ids.0');
});

it('creates an event scoped to only the given departments', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    eventTestSemester($admin);
    $bsit = eventTestDept('BSIT');
    $bed = eventTestDept('BED');
    eventTestDept('BSBA'); // not included — should be excluded from scope

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/events', [
            'name' => 'BSIT + BEd only Intramurals',
            'department_ids' => [$bsit->id, $bed->id],
        ])
        ->assertStatus(201)
        ->json();

    $event = EventModel::with('departments')->find($response['id']);

    expect($event->includesDepartment($bsit->id))->toBeTrue()
        ->and($event->includesDepartment($bed->id))->toBeTrue()
        ->and($event->departments)->toHaveCount(2);
});

it('creates a day and then a session under it, nested via the route', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $day = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/days", ['date' => '2026-11-10', 'day_number' => 1])
        ->assertStatus(201)
        ->json();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day['id']}/sessions", [
            'window_type' => 'morning',
            'check_type' => 'time_in',
            'start_time' => '07:00',
            'end_time' => '08:00',
            'grace_minutes' => 30,
        ])
        ->assertStatus(201)
        ->assertJsonPath('window_type', 'morning')
        ->assertJsonPath('check_type', 'time_in');
});

it('rejects a duplicate day_number within the same event', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/days", ['date' => '2026-11-10', 'day_number' => 1])
        ->assertStatus(201);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/days", ['date' => '2026-11-11', 'day_number' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('day_number');
});

it('rejects a duplicate window_type + check_type within the same day', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = \App\Models\EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_in', 'start_time' => '07:00', 'end_time' => '08:00',
        ])
        ->assertStatus(201);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_in', 'start_time' => '09:00', 'end_time' => '10:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('window_type');
});

it('allows a time-out session for the same window_type once time-in already exists', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = \App\Models\EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_in', 'start_time' => '07:00', 'end_time' => '08:00',
        ])
        ->assertStatus(201);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_out', 'start_time' => '16:00', 'end_time' => '17:00',
        ])
        ->assertStatus(201);
});

it('returns 409 adding a new day to an already-ended event', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create([
        'name' => 'Intramurals 2026', 'created_by' => $admin->id, 'status' => \App\Domain\Enums\EventStatus::Ended,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/events/{$event->id}/days", ['date' => '2026-11-10', 'day_number' => 1])
        ->assertStatus(409);

    expect($event->days()->count())->toBe(0);
});

it('returns 409 adding a new session to a day whose event has already ended', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create([
        'name' => 'Intramurals 2026', 'created_by' => $admin->id, 'status' => \App\Domain\Enums\EventStatus::Ended,
    ]);
    $day = \App\Models\EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_in', 'start_time' => '07:00', 'end_time' => '08:00',
        ])
        ->assertStatus(409);

    expect($day->sessions()->count())->toBe(0);
});

it('rejects a session where end_time is not after start_time', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = \App\Models\EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/event-days/{$day->id}/sessions", [
            'window_type' => 'morning', 'check_type' => 'time_in', 'start_time' => '08:00', 'end_time' => '07:00',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('end_time');
});

// event-day-window-edit-delete-plan.md §6: PATCH /events/{event} (UpdateEvent).

it('rejects an unauthenticated event update', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->patchJson("/api/events/{$event->id}", ['name' => 'New Name'])
        ->assertStatus(401);
});

it('rejects event update from a non-admin role', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $officer = eventTestStaff('officer', '2020200001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($officer, 'sanctum')
        ->patchJson("/api/events/{$event->id}", ['name' => 'New Name'])
        ->assertStatus(403);
});

it('lets a csg admin update an event name and description', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/events/{$event->id}", ['name' => 'Renamed', 'description' => 'Updated'])
        ->assertStatus(200)
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('description', 'Updated');
});

it('allows a partial event update touching only one field', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'description' => 'Original', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/events/{$event->id}", ['name' => 'Renamed'])
        ->assertStatus(200)
        ->assertJsonPath('name', 'Renamed')
        ->assertJsonPath('description', 'Original');
});

it('rejects updating an already-ended event with a 409', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create([
        'name' => 'Intramurals 2026', 'created_by' => $admin->id, 'status' => \App\Domain\Enums\EventStatus::Ended,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/events/{$event->id}", ['name' => 'Renamed'])
        ->assertStatus(409);
});

it('returns 404 when updating a nonexistent event', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->patchJson('/api/events/999999', ['name' => 'Renamed'])
        ->assertStatus(404);
});

it('rejects an event update with an empty name', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->patchJson("/api/events/{$event->id}", ['name' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('rejects an unauthenticated event delete', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Duplicate Intramurals', 'created_by' => $admin->id]);

    $this->deleteJson("/api/events/{$event->id}")->assertStatus(401);
});

it('rejects event delete from a non-admin role', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $officer = eventTestStaff('officer', '2020200001');
    $event = EventModel::create(['name' => 'Duplicate Intramurals', 'created_by' => $admin->id]);

    $this->actingAs($officer, 'sanctum')
        ->deleteJson("/api/events/{$event->id}")
        ->assertStatus(403);

    expect(EventModel::find($event->id))->not->toBeNull();
});

it('lets a csg admin delete a freshly created event with nothing under it', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Duplicate Intramurals', 'created_by' => $admin->id]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/events/{$event->id}")
        ->assertStatus(200)
        ->assertJsonPath('event_id', $event->id);

    expect(EventModel::find($event->id))->toBeNull();
});

it('rejects deleting an already-ended event with a 409', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');
    $event = EventModel::create([
        'name' => 'Duplicate Intramurals', 'created_by' => $admin->id, 'status' => \App\Domain\Enums\EventStatus::Ended,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/events/{$event->id}")
        ->assertStatus(409);

    expect(EventModel::find($event->id))->not->toBeNull();
});

it('returns 404 when deleting a nonexistent event', function () {
    $admin = eventTestStaff('csg_admin', '2020000001');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson('/api/events/999999')
        ->assertStatus(404);
});
