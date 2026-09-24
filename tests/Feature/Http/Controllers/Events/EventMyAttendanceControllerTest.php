<?php

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function myAttendanceTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function myAttendanceTestStudent(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => ucfirst($role),
        'department_id' => myAttendanceTestDept()->id,
        'username' => 'user'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Clears the photo.uploaded gate — see the same note in
        // ReverseScanControllerTest. This suite exercises "my attendance"
        // reporting, not the photo gate (covered separately by
        // EnsurePhotoHasBeenUploadedTest).
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

it('rejects unauthenticated requests', function () {
    $admin = myAttendanceTestStudent('csg_admin', '2020000001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);

    $this->getJson("/api/events/{$event->id}/my-attendance")->assertStatus(401);
});

it('reports a session with no record yet as pending (null)', function () {
    $student = myAttendanceTestStudent('student', '2020300001');
    $admin = myAttendanceTestStudent('csg_admin', '2020000002');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson("/api/events/{$event->id}/my-attendance")
        ->assertStatus(200)
        ->json();

    expect($response['days'][0]['sessions'][0]['id'])->toBe($session->id);
    expect($response['days'][0]['sessions'][0]['attendance_status'])->toBeNull();
    expect($response['days'][0]['sessions'][0]['session_status'])->toBe('scheduled');
});

it('reports the caller\'s own scanned record, not anyone else\'s', function () {
     $student = myAttendanceTestStudent('student', '2020300002');
    $otherStudent = myAttendanceTestStudent('student', '2020300003');
    $admin = myAttendanceTestStudent('csg_admin', '2020000003');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'ongoing',
    ]);

    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'scanned_at' => now(),
        'status' => 'present',
    ]);
    AttendanceRecord::create([
        'session_id' => $session->id,
        'student_id' => $otherStudent->id,
        'scanned_at' => now(),
        'status' => 'late',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson("/api/events/{$event->id}/my-attendance")
        ->assertStatus(200)
        ->json();

    expect($response['days'][0]['sessions'][0]['attendance_status'])->toBe('present');
    expect($response['days'][0]['sessions'][0]['scanned_at'])->not->toBeNull();
});

it('reports who scanned the record, but null when nobody has scanned yet', function () {
    $student = myAttendanceTestStudent('student', '2020300005');
    $officer = myAttendanceTestStudent('officer', '2020200001');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $officer->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $scannedSession = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'ongoing',
    ]);
    $pendingSession = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'afternoon',
        'check_type' => 'time_in',
        'start_time' => '13:00',
        'end_time' => '14:00',
        'status' => 'scheduled',
    ]);

    AttendanceRecord::create([
        'session_id' => $scannedSession->id,
        'student_id' => $student->id,
        'scanned_by' => $officer->id,
        'scanned_at' => now(),
        'status' => 'present',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson("/api/events/{$event->id}/my-attendance")
        ->assertStatus(200)
        ->json();

    $sessions = collect($response['days'][0]['sessions'])->keyBy('id');

    expect($sessions[$scannedSession->id]['scanned_by_name'])->toBe('Officer Test');
    expect($sessions[$pendingSession->id]['scanned_by_name'])->toBeNull();
});

it('reports excluded rather than pending for an excluded student', function () {
    $student = myAttendanceTestStudent('student', '2020300004');
    $admin = myAttendanceTestStudent('csg_admin', '2020000009');
    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'scheduled',
    ]);

    Exclusion::create([
        'student_id' => $student->id,
        'event_id' => $event->id,
        'scope' => 'event',
        'reason' => 'Testing exclusion',
        'created_by' => $admin->id,
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson("/api/events/{$event->id}/my-attendance")
        ->assertStatus(200)
        ->json();

    expect($response['days'][0]['sessions'][0]['attendance_status'])->toBe('excluded');
});
