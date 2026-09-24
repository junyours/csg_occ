<?php

use App\Application\Actions\Reports\BuildMasterRosterReport;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function masterRosterDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function masterRosterCreator(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000099'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => masterRosterDept('CCS')->id,
            'username' => 'mrcsgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

function masterRosterEvent(string $name): EventModel
{
    return EventModel::create(['name' => $name, 'created_by' => masterRosterCreator()->id]);
}

function masterRosterSession(EventModel $event, int $dayNumber = 1, array $overrides = []): AttendanceSession
{
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => $dayNumber],
        ['date' => now()->addDays($dayNumber - 1)->toDateString()],
    );

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00', 'end_time' => '08:00:00', 'grace_minutes' => 15,
        'penalty_late_amount' => 10, 'penalty_absent_amount' => 25,
        'status' => 'ended',
    ], $overrides));
}

function masterRosterStudent(string $studentNumber, string $deptCode = 'BSIT'): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => masterRosterDept($deptCode)->id,
        'year_level' => '1', 'section' => 'A',
        'username' => 'mrstudent'.$studentNumber,
        'password' => 'password',
    ]);
}

it('spans sessions from every given event, in the order the events were given', function () {
    $eventOne = masterRosterEvent('Intramurals 2026');
    $eventTwo = masterRosterEvent('Foundation Week 2026');

    $sessionOne = masterRosterSession($eventOne, 1);
    $sessionTwo = masterRosterSession($eventTwo, 1);

    $report = (new BuildMasterRosterReport)([$eventOne, $eventTwo]);

    expect($report['events'])->toBe([
        ['id' => $eventOne->id, 'name' => $eventOne->name],
        ['id' => $eventTwo->id, 'name' => $eventTwo->name],
    ])
        ->and(array_column($report['sessions'], 'id'))->toBe([$sessionOne->id, $sessionTwo->id])
        ->and($report['sessions'][0]['event_id'])->toBe($eventOne->id)
        ->and($report['sessions'][1]['event_id'])->toBe($eventTwo->id);
});

it('uses one shared roster across every event rather than one roster per event', function () {
    $eventOne = masterRosterEvent('Intramurals 2026');
    $eventTwo = masterRosterEvent('Foundation Week 2026');
    masterRosterSession($eventOne, 1);
    masterRosterSession($eventTwo, 1);

    masterRosterStudent('2023100001');
    masterRosterStudent('2023100002');

    $report = (new BuildMasterRosterReport)([$eventOne, $eventTwo]);

    expect($report['groups'])->toHaveCount(1)
        ->and($report['groups'][0]['students'])->toHaveCount(2);
});

it('reflects each event\'s own attendance status per session, per student', function () {
    $eventOne = masterRosterEvent('Intramurals 2026');
    $eventTwo = masterRosterEvent('Foundation Week 2026');
    $sessionOne = masterRosterSession($eventOne, 1);
    $sessionTwo = masterRosterSession($eventTwo, 1);

    $student = masterRosterStudent('2023100003');

    AttendanceRecord::create([
        'session_id' => $sessionOne->id, 'student_id' => $student->id,
        'status' => AttendanceStatus::Present,
    ]);
    AttendanceRecord::create([
        'session_id' => $sessionTwo->id, 'student_id' => $student->id,
        'status' => AttendanceStatus::Absent,
    ]);

    $report = (new BuildMasterRosterReport)([$eventOne, $eventTwo]);
    $row = $report['groups'][0]['students'][0];

    expect($row['sessions'][$sessionOne->id])->toBe('present')
        ->and($row['sessions'][$sessionTwo->id])->toBe('absent');
});

it('sums penalties across every selected event into one total per student', function () {
    $eventOne = masterRosterEvent('Intramurals 2026');
    $eventTwo = masterRosterEvent('Foundation Week 2026');
    $sessionOne = masterRosterSession($eventOne, 1);
    $sessionTwo = masterRosterSession($eventTwo, 1);

    $student = masterRosterStudent('2023100004');

    \App\Models\AttendancePenalty::create([
        'session_id' => $sessionOne->id, 'student_id' => $student->id,
        'amount' => 10, 'reason' => 'late', 'is_reversed' => false,
    ]);
    \App\Models\AttendancePenalty::create([
        'session_id' => $sessionTwo->id, 'student_id' => $student->id,
        'amount' => 25, 'reason' => 'absent', 'is_reversed' => false,
    ]);

    $report = (new BuildMasterRosterReport)([$eventOne, $eventTwo]);

    expect($report['groups'][0]['students'][0]['penalty_total'])->toBe(35.0);
});

it('filters the shared roster to a single department, year level, and section', function () {
    $eventOne = masterRosterEvent('Intramurals 2026');
    masterRosterSession($eventOne, 1);

    masterRosterStudent('2023100005', 'BSIT');
    masterRosterStudent('2023100006', 'BSBA');

    $bsit = masterRosterDept('BSIT');

    $report = (new BuildMasterRosterReport)([$eventOne], departmentId: $bsit->id);

    expect($report['groups'])->toHaveCount(1)
        ->and($report['groups'][0]['department_code'])->toBe('BSIT');
});
