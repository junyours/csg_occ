<?php

use App\Application\Actions\Attendance\BuildAttendanceHistory;
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

function historyDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function historyOfficer(string $studentNumber = '2020000002'): Student
{
    return Student::firstOrCreate(
        ['student_number' => $studentNumber],
        [
            'last_name' => 'Gate', 'first_name' => 'Officer',
            'department_id' => historyDept('CCS')->id,
            'username' => 'off'.$studentNumber,
            'password' => 'password',
            'role' => 'officer',
        ],
    );
}

function historyStudent(string $studentNumber, string $deptCode = 'CCS', array $overrides = []): Student
{
    return Student::create(array_merge([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => historyDept($deptCode)->id,
        'major' => null, 'year_level' => '1', 'section' => 'A',
        'username' => 'stu'.$studentNumber,
        'password' => 'password',
    ], $overrides));
}

function historySession(EventModel $event): AttendanceSession
{
    $day = EventDay::create([
        'event_id' => $event->id,
        'date' => '2026-11-10',
        'day_number' => $event->days()->count() + 1,
    ]);

    return AttendanceSession::create([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'penalty_late_amount' => 10,
        'penalty_absent_amount' => 25,
    ]);
}

function historyRecord(Student $student, AttendanceSession $session, Student $scanner, array $overrides = []): AttendanceRecord
{
    return AttendanceRecord::create(array_merge([
        'session_id' => $session->id,
        'student_id' => $student->id,
        'scanned_by' => $scanner->id,
        'scanned_at' => now(),
        'status' => 'present',
    ], $overrides));
}

it('lists every attendance record with the scanning officer, newest first', function () {
    $officer = historyOfficer();
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $officer->id]);
    $session = historySession($event);

    $older = historyRecord(historyStudent('2020400001'), $session, $officer);
    $older->forceFill(['scanned_at' => now()->subHour()])->save();
    $newer = historyRecord(historyStudent('2020400002'), $session, $officer);

    $page = (new BuildAttendanceHistory)([]);

    expect($page->total())->toBe(2)
        ->and($page->items()[0]['id'])->toBe($newer->id)
        ->and($page->items()[1]['id'])->toBe($older->id)
        ->and($page->items()[0]['scanned_by_name'])->toBe('Officer Gate')
        ->and($page->items()[0]['scanned_by_role'])->toBe('officer')
        ->and($page->items()[0]['event_name'])->toBe('Intrams');
});

it('filters by course, major, year level, and section', function () {
    $officer = historyOfficer();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);
    $session = historySession($event);

    $target = historyStudent('2020400003', 'CCS', ['major' => 'BSIT', 'year_level' => '2', 'section' => 'B']);
    $other = historyStudent('2020400004', 'BSBA', ['major' => 'BSBA', 'year_level' => '1', 'section' => 'A']);
    historyRecord($target, $session, $officer);
    historyRecord($other, $session, $officer);

    $byDept = (new BuildAttendanceHistory)(['department_id' => historyDept('CCS')->id]);
    $byMajor = (new BuildAttendanceHistory)(['major' => 'BSIT']);
    $byYear = (new BuildAttendanceHistory)(['year_level' => '2']);
    $bySection = (new BuildAttendanceHistory)(['section' => 'B']);

    expect($byDept->total())->toBe(1)->and($byDept->items()[0]['student_id'])->toBe($target->id)
        ->and($byMajor->total())->toBe(1)->and($byMajor->items()[0]['student_id'])->toBe($target->id)
        ->and($byYear->total())->toBe(1)->and($byYear->items()[0]['student_id'])->toBe($target->id)
        ->and($bySection->total())->toBe(1)->and($bySection->items()[0]['student_id'])->toBe($target->id);
});

it('filters by event and by status', function () {
    $officer = historyOfficer();
    $eventOne = EventModel::create(['name' => 'Event One', 'created_by' => $officer->id]);
    $eventTwo = EventModel::create(['name' => 'Event Two', 'created_by' => $officer->id]);

    $present = historyRecord(historyStudent('2020400005'), historySession($eventOne), $officer, ['status' => 'present']);
    historyRecord(historyStudent('2020400006'), historySession($eventOne), $officer, ['status' => 'late']);
    historyRecord(historyStudent('2020400007'), historySession($eventTwo), $officer, ['status' => 'absent']);

    $eventOneOnly = (new BuildAttendanceHistory)(['event_id' => $eventOne->id]);
    $presentOnly = (new BuildAttendanceHistory)(['status' => 'present']);

    expect($eventOneOnly->total())->toBe(2)
        ->and($presentOnly->total())->toBe(1)
        ->and($presentOnly->items()[0]['id'])->toBe($present->id);
});

it('searches by attendee name and by scanning officer name', function () {
    $officer = historyOfficer('2020000003');
    $otherOfficer = Student::create([
        'student_number' => '2020000004', 'last_name' => 'Reyes', 'first_name' => 'Second',
        'department_id' => historyDept('CCS')->id, 'username' => 'off2', 'password' => 'password', 'role' => 'officer',
    ]);
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);
    $session = historySession($event);

    $target = historyStudent('2020499999', 'CCS', ['last_name' => 'Delacruz']);
    historyRecord($target, $session, $officer);
    historyRecord(historyStudent('2020400008', 'CCS', ['last_name' => 'Santos']), $session, $otherOfficer);

    $byStudentName = (new BuildAttendanceHistory)(['search' => 'Delacruz']);
    $byScannerName = (new BuildAttendanceHistory)(['search' => 'Reyes']);

    expect($byStudentName->total())->toBe(1)
        ->and($byStudentName->items()[0]['student_id'])->toBe($target->id)
        ->and($byScannerName->total())->toBe(1)
        ->and($byScannerName->items()[0]['scanned_by_name'])->toBe('Second Reyes');
});

it('sorts by name when requested', function () {
    $officer = historyOfficer();
    $event = EventModel::create(['name' => 'Event', 'created_by' => $officer->id]);
    $session = historySession($event);

    historyRecord(historyStudent('2020400009', 'CCS', ['last_name' => 'Zamora']), $session, $officer);
    historyRecord(historyStudent('2020400010', 'CCS', ['last_name' => 'Alonzo']), $session, $officer);

    $page = (new BuildAttendanceHistory)(['sort' => 'name']);

    expect($page->items()[0]['last_name'])->toBe('Alonzo')
        ->and($page->items()[1]['last_name'])->toBe('Zamora');
});

it('carries the student photo url so the ledger can render a real avatar', function () {
    $officer = historyOfficer();
    $event = EventModel::create(['name' => 'Intrams', 'created_by' => $officer->id]);
    $session = historySession($event);

    $withPhoto = historyStudent('2020400050', 'CCS', ['photo_path' => 'photos/2020400050.jpg']);
    $withoutPhoto = historyStudent('2020400051');

    historyRecord($withPhoto, $session, $officer);
    historyRecord($withoutPhoto, $session, $officer);

    $rows = collect((new BuildAttendanceHistory)([])->items())->keyBy('student_number');

    expect($rows['2020400050']['photo_url'])->toBe('/storage/photos/2020400050.jpg')
        // Null rather than absent — UserAvatar keys its initials fallback
        // off this being null, so the field has to be present either way.
        ->and($rows['2020400051'])->toHaveKey('photo_url')
        ->and($rows['2020400051']['photo_url'])->toBeNull();
});
