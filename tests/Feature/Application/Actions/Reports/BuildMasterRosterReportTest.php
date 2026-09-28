<?php

use App\Application\Actions\Reports\BuildMasterRosterReport;
use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Exports\MasterRosterReportExport;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
            'role' => 'system_admin',
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
        'status' => AttendanceStatus::Missed,
    ]);

    $report = (new BuildMasterRosterReport)([$eventOne, $eventTwo]);
    $row = $report['groups'][0]['students'][0];

    expect($row['sessions'][$sessionOne->id])->toBe('present')
        ->and($row['sessions'][$sessionTwo->id])->toBe('missed');
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
        'amount' => 25, 'reason' => 'missed', 'is_reversed' => false,
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

function masterRosterSemester(string $yearName, string $term, bool $active): Semester
{
    $year = AcademicYear::create([
        'name' => $yearName, 'is_active' => $active, 'created_by' => masterRosterCreator()->id,
    ]);

    return Semester::create([
        'academic_year_id' => $year->id, 'name' => $term, 'is_active' => $active,
        'created_by' => masterRosterCreator()->id,
    ]);
}

function masterRosterEventIn(?Semester $semester, string $name): EventModel
{
    return EventModel::create([
        'name' => $name, 'created_by' => masterRosterCreator()->id, 'semester_id' => $semester?->id,
    ]);
}

it('keeps a multi-semester selection together by semester, in the order semesters first appear', function () {
    $current = masterRosterSemester('2026-2027', 'semester_1', true);
    $past = masterRosterSemester('2025-2026', 'semester_2', false);

    $a = masterRosterEventIn($current, 'Current A');
    $b = masterRosterEventIn($past, 'Past B');
    $c = masterRosterEventIn($current, 'Current C');
    $sessionA = masterRosterSession($a, 1);
    $sessionB = masterRosterSession($b, 1);
    $sessionC = masterRosterSession($c, 1);

    // Interleaved on purpose: current, past, current.
    $report = (new BuildMasterRosterReport)([$a, $b, $c]);

    expect(array_column($report['events'], 'id'))->toBe([$a->id, $c->id, $b->id])
        ->and(array_column($report['sessions'], 'id'))->toBe([$sessionA->id, $sessionC->id, $sessionB->id])
        ->and($report['spans_semesters'])->toBeTrue()
        ->and($report['semesters'])->toBe([
            [
                'id' => $current->id,
                'label' => '2026-2027 — Semester 1 (current)',
                'is_current' => true,
                'event_ids' => [$a->id, $c->id],
            ],
            [
                'id' => $past->id,
                'label' => '2025-2026 — Semester 2',
                'is_current' => false,
                'event_ids' => [$b->id],
            ],
        ]);
});

it('leaves a single-semester selection exactly as given', function () {
    $semester = masterRosterSemester('2026-2027', 'semester_1', true);
    $first = masterRosterEventIn($semester, 'First');
    $second = masterRosterEventIn($semester, 'Second');
    masterRosterSession($first, 1);
    masterRosterSession($second, 1);

    $report = (new BuildMasterRosterReport)([$second, $first]);

    expect(array_column($report['events'], 'id'))->toBe([$second->id, $first->id])
        ->and($report['spans_semesters'])->toBeFalse()
        ->and($report['semesters'])->toHaveCount(1);
});

it('treats events with no semester as one unlabelled block, not a semester span', function () {
    $one = masterRosterEvent('Legacy One');
    $two = masterRosterEvent('Legacy Two');
    masterRosterSession($one, 1);
    masterRosterSession($two, 1);

    $report = (new BuildMasterRosterReport)([$one, $two]);

    expect($report['spans_semesters'])->toBeFalse()
        ->and($report['semesters'])->toHaveCount(1)
        ->and($report['semesters'][0]['id'])->toBeNull()
        ->and($report['semesters'][0]['label'])->toBe('No semester');
});

it('prints a semester header row above the event row in the Excel export only when semesters differ', function () {
    Storage::fake('local');

    $current = masterRosterSemester('2026-2027', 'semester_1', true);
    $past = masterRosterSemester('2025-2026', 'semester_2', false);
    $a = masterRosterEventIn($current, 'Current A');
    $b = masterRosterEventIn($past, 'Past B');
    $sessionA = masterRosterSession($a, 1);
    masterRosterSession($b, 1);
    $student = masterRosterStudent('2023100050');

    // A selection across semesters is record-driven (historical roster),
    // so the student needs a record for a sheet to be produced at all.
    AttendanceRecord::create([
        'session_id' => $sessionA->id, 'student_id' => $student->id,
        'status' => AttendanceStatus::Present,
    ]);

    $spanning = (new BuildMasterRosterReport)([$a, $b]);
    Excel::store(new MasterRosterReportExport($spanning), 'spanning.xlsx', 'local');
    $sheet = IOFactory::load(Storage::disk('local')->path('spanning.xlsx'))->getSheet(0);

    // Row 4 = semester, row 5 = event, session columns start at D.
    expect($sheet->getCell('D4')->getValue())->toBe('2026-2027 — Semester 1 (current)')
        ->and($sheet->getCell('E4')->getValue())->toBe('2025-2026 — Semester 2')
        ->and($sheet->getCell('D5')->getValue())->toBe('Current A')
        ->and($sheet->getCell('E5')->getValue())->toBe('Past B');

    $single = (new BuildMasterRosterReport)([$a]);
    Excel::store(new MasterRosterReportExport($single), 'single.xlsx', 'local');
    $sheet = IOFactory::load(Storage::disk('local')->path('single.xlsx'))->getSheet(0);

    // No semester row: the event row is still row 4.
    expect($sheet->getCell('D4')->getValue())->toBe('Current A');
});
