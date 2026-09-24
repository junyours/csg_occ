<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function attendanceRecordEventTestSetup(string $eventName): array
{
    $departmentCode = 'CCS-'.substr(md5($eventName), 0, 6);
    $department = Department::create(['name' => 'CCS', 'code' => $departmentCode]);

    $admin = Student::create([
        'student_number' => 'TEST-ADMIN-'.$eventName,
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => $department->id,
        'username' => 'admin-'.$eventName, 'password' => 'password',
    ]);

    $student = Student::create([
        'student_number' => 'TEST-STUDENT-'.$eventName,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => $department->id,
        'username' => 'student-'.$eventName, 'password' => 'password',
    ]);

    $event = EventModel::create(['name' => $eventName, 'created_by' => $admin->id]);
    $day = EventDay::create(['event_id' => $event->id, 'date' => '2026-11-10', 'day_number' => 1]);
    $session = AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Ended,
    ]);

    return [$event, $session, $student, $admin];
}

it('scopes present, absent, and late records to the event they belong to', function () {
    [$eventOne, $sessionOne, $studentOne, $admin] = attendanceRecordEventTestSetup('Event One');
    [$eventTwo, $sessionTwo, $studentTwo] = attendanceRecordEventTestSetup('Event Two');

    AttendanceRecord::create([
        'session_id' => $sessionOne->id, 'student_id' => $studentOne->id,
        'status' => AttendanceStatus::Present, 'scanned_at' => now(), 'scanned_by' => $admin->id,
    ]);
    AttendanceRecord::create([
        'session_id' => $sessionOne->id, 'student_id' => $admin->id,
        'status' => AttendanceStatus::Late, 'scanned_at' => now(), 'scanned_by' => $admin->id,
    ]);
    AttendanceRecord::create([
        'session_id' => $sessionTwo->id, 'student_id' => $studentTwo->id,
        'status' => AttendanceStatus::Absent, 'scanned_by' => $admin->id,
    ]);

    $eventOneRecords = AttendanceRecord::forEvent($eventOne->id)->get();
    $eventTwoRecords = AttendanceRecord::forEvent($eventTwo->id)->get();

    expect($eventOneRecords)->toHaveCount(2)
        ->and($eventOneRecords->pluck('student_id')->all())->toContain($studentOne->id, $admin->id)
        ->and($eventTwoRecords)->toHaveCount(1)
        ->and($eventTwoRecords->first()->student_id)->toBe($studentTwo->id)
        ->and($eventTwoRecords->first()->status)->toBe(AttendanceStatus::Absent);
});
