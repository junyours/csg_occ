<?php

use App\Models\Department;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// GET /api/server-time — the frontend's one source of truth for "what
// time is it, really", used to gate a session's Start button against a
// clock the viewer can't change (see useTrustedNow/useServerTimeOffsetMs
// on the frontend and StartSession/ScanAttendance's server-clock checks
// on the backend, which this endpoint has no bearing on but exists to
// mirror for the UI).

function serverTimeStudent(): Student
{
    $department = Department::firstOrCreate(['code' => 'CCS'], ['name' => 'CCS']);

    // firstOrCreate() inserts the row and the DB applies its schema
    // defaults (must_change_password: true), but Eloquent never
    // re-hydrates DB-side column defaults back onto the in-memory model
    // — the same gotcha CreateSession's own refresh() call guards
    // against. Without refreshing, $student->must_change_password would
    // read back as null here even though the DB row already has it true.
    return Student::firstOrCreate(
        ['student_number' => '2023105413'],
        [
            'last_name' => 'Cruz', 'first_name' => 'Juan',
            'department_id' => $department->id,
            'username' => 'jcruz', 'password' => 'password',
        ],
    )->refresh();
}

it('rejects an unauthenticated request', function () {
    $this->getJson('/api/server-time')->assertStatus(401);
});

it('returns the application server\'s current time, not anything client-supplied', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-10 14:45:03', 'Asia/Manila'));

    $this->actingAs(serverTimeStudent(), 'sanctum')
        ->getJson('/api/server-time')
        ->assertStatus(200)
        ->assertJson(['now' => Carbon::now()->toIso8601String()]);

    Carbon::setTestNow();
});

it('is reachable even for a student who still must change their password and has no photo on file', function () {
    // serverTimeStudent() is created with the schema's defaults —
    // must_change_password: true, no photo_path — either of which would
    // block most other authenticated routes (see EnsurePasswordHasBeenChanged
    // / EnsurePhotoHasBeenUploaded). /server-time deliberately sits outside
    // both gates: a client should be able to sync its clock as soon as
    // it's logged in, before clearing anything else.
    $student = serverTimeStudent();
    expect($student->must_change_password)->toBeTrue()
        ->and($student->photo_path)->toBeNull();

    $this->actingAs($student, 'sanctum')
        ->getJson('/api/server-time')
        ->assertStatus(200);
});
