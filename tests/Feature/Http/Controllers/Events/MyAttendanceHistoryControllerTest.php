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

function historyTestDept(string $code = 'CCS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function historyTestStudent(string $role, string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Test', 'first_name' => ucfirst($role),
        'department_id' => historyTestDept()->id,
        'username' => 'user'.$studentNumber,
        'password' => 'password',
        'role' => $role,
        // Clears the photo.uploaded gate — see the same note in
        // ReverseScanControllerTest. This suite exercises attendance
        // history, not the photo gate (covered separately by
        // EnsurePhotoHasBeenUploadedTest).
        'photo_path' => 'students/placeholder.jpg',
    ]);
}

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/my-attendance-history')->assertStatus(401);
});

it('flattens every event into one row per session, newest event first', function () {
    $student = historyTestStudent('student', '2020400001');
    $admin = historyTestStudent('csg_admin', '2020000010');

    $olderEvent = EventModel::create(['name' => 'Freshmen Orientation', 'created_by' => $admin->id]);
    $olderDay = EventDay::create(['event_id' => $olderEvent->id, 'date' => '2026-08-01', 'day_number' => 1]);
    $olderSession = AttendanceSession::create([
        'event_day_id' => $olderDay->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'ended',
    ]);
    AttendanceRecord::create([
        'session_id' => $olderSession->id,
        'student_id' => $student->id,
        'scanned_at' => now(),
        'status' => 'late',
    ]);

    $newerEvent = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $newerDay = EventDay::create(['event_id' => $newerEvent->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $newerSession = AttendanceSession::create([
        'event_day_id' => $newerDay->id,
        'window_type' => 'morning',
        'check_type' => 'time_in',
        'start_time' => '07:00',
        'end_time' => '08:00',
        'status' => 'scheduled',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/my-attendance-history')
        ->assertStatus(200)
        ->json('entries');

    expect($response)->toHaveCount(2);
    expect($response[0]['event_id'])->toBe($newerEvent->id);
    expect($response[0]['attendance_status'])->toBeNull();
    expect($response[0]['scanned_by_name'])->toBeNull();
    expect($response[1]['event_id'])->toBe($olderEvent->id);
    expect($response[1]['attendance_status'])->toBe('late');
    expect($response[1]['scanned_at'])->not->toBeNull();
});

it('includes who scanned each record, for the "who scanned this" audit ask', function () {
    $student = historyTestStudent('student', '2020400005');
    $officer = historyTestStudent('officer', '2020200002');

    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $officer->id]);
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
        'scanned_by' => $officer->id,
        'scanned_at' => now(),
        'status' => 'present',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/my-attendance-history')
        ->assertStatus(200)
        ->json('entries');

    expect($response[0]['scanned_by_name'])->toBe('Officer Test');
});

it('never mixes in another student\'s record', function () {
    $student = historyTestStudent('student', '2020400002');
    $otherStudent = historyTestStudent('student', '2020400003');
    $admin = historyTestStudent('csg_admin', '2020000011');

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
        'student_id' => $otherStudent->id,
        'scanned_at' => now(),
        'status' => 'present',
    ]);

    $response = $this->actingAs($student, 'sanctum')
        ->getJson('/api/my-attendance-history')
        ->assertStatus(200)
        ->json('entries');

    expect($response)->toHaveCount(1);
    expect($response[0]['attendance_status'])->toBeNull();
});

it('reports excluded rather than pending', function () {
    $student = historyTestStudent('student', '2020400004');
    $admin = historyTestStudent('csg_admin', '2020000012');

    $event = EventModel::create(['name' => 'Intramurals 2026', 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    AttendanceSession::create([
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
        ->getJson('/api/my-attendance-history')
        ->assertStatus(200)
        ->json('entries');

    expect($response[0]['attendance_status'])->toBe('excluded');
});
