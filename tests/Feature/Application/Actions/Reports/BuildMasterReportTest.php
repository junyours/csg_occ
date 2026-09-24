<?php

use App\Application\Actions\Reports\BuildMasterReport;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AcademicYear;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function masterReportDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function masterReportAdmin(): Student
{
    return Student::firstOrCreate(
        ['student_number' => '2020000001'],
        [
            'last_name' => 'Admin', 'first_name' => 'CSG',
            'department_id' => masterReportDept('CCS')->id,
            'username' => 'csgadmin', 'password' => 'password',
            'role' => 'csg_admin',
        ],
    );
}

// The active academic year + semester is a hard requirement of
// BuildMasterReport (no active pair = an empty report) — every test
// needs one, so it's set up once here rather than repeated per test.
function masterReportActiveSemester(): Semester
{
    $admin = masterReportAdmin();
    $academicYear = AcademicYear::firstOrCreate(
        ['name' => '2026-2027'],
        ['is_active' => true, 'created_by' => $admin->id],
    );

    return Semester::firstOrCreate(
        ['academic_year_id' => $academicYear->id, 'name' => 'semester_1'],
        ['is_active' => true, 'created_by' => $admin->id],
    );
}

function masterReportEvent(string $name, array $departmentIds = []): EventModel
{
    $event = EventModel::create([
        'name' => $name,
        'created_by' => masterReportAdmin()->id,
        'semester_id' => masterReportActiveSemester()->id,
    ]);

    if ($departmentIds !== []) {
        $event->departments()->sync($departmentIds);
    }

    return $event;
}

function masterReportSession(EventModel $event, array $overrides = []): AttendanceSession
{
    $day = EventDay::firstOrCreate(
        ['event_id' => $event->id, 'day_number' => 1],
        ['date' => '2026-11-10'],
    );

    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => WindowType::Morning,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00', 'end_time' => '08:00:00', 'grace_minutes' => 15,
        'status' => 'ended',
    ], $overrides));
}

function masterReportStudent(string $studentNumber, string $deptCode): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Student', 'first_name' => 'Test',
        'department_id' => masterReportDept($deptCode)->id,
        'username' => 'mrstudent'.$studentNumber,
        'password' => 'password',
    ]);
}

it('returns an empty report when there is no active academic year or semester', function () {
    $report = (new BuildMasterReport)();

    expect($report['academic_year'])->toBeNull()
        ->and($report['semester'])->toBeNull()
        ->and($report['events'])->toBe([]);
});

it('lists every event in the active academic year and semester with its status', function () {
    masterReportActiveSemester();
    $event = masterReportEvent('Intramurals 2026');

    $report = (new BuildMasterReport)();

    expect($report['events'])->toHaveCount(1)
        ->and($report['events'][0]['id'])->toBe($event->id)
        ->and($report['events'][0]['name'])->toBe('Intramurals 2026');
});

it('counts absent students against an unrestricted event regardless of department', function () {
    $event = masterReportEvent('Open Event'); // no department_ids — unrestricted
    masterReportSession($event);
    masterReportStudent('2023100001', 'BSIT');
    masterReportStudent('2023100002', 'BSBA');

    $report = (new BuildMasterReport)();

    expect($report['events'][0]['absent'])->toBe(2);
});

it('never counts a student whose department is outside the event scope, in absent totals', function () {
    $bsit = masterReportDept('BSIT');
    $bed = masterReportDept('BED');
    $bsba = masterReportDept('BSBA');

    $event = masterReportEvent('BSIT + BEd only Intramurals', [$bsit->id, $bed->id]);
    masterReportSession($event);

    masterReportStudent('2023100003', 'BSIT'); // included — counted
    masterReportStudent('2023100004', 'BED'); // included — counted
    masterReportStudent('2023100005', 'BSBA'); // excluded — must never be counted

    $report = (new BuildMasterReport)();

    // Only the two in-scope students were ever eligible, so only they
    // get swept into the absent total — the out-of-scope BSBA student
    // was never part of this event's roster in the first place.
    expect($report['events'][0]['absent'])->toBe(2);
});

it('never counts a student whose department is outside the event scope, in present totals', function () {
    $bsit = masterReportDept('BSIT');
    $bsba = masterReportDept('BSBA');

    $event = masterReportEvent('BSIT-only Event', [$bsit->id]);
    $session = masterReportSession($event);

    $bsitStudent = masterReportStudent('2023100006', 'BSIT');
    $bsbaStudent = masterReportStudent('2023100007', 'BSBA');

    \App\Models\AttendanceRecord::create([
        'session_id' => $session->id, 'student_id' => $bsitStudent->id,
        'status' => 'present',
    ]);
    // A record for the excluded-department student shouldn't exist in
    // practice (ScanAttendance blocks it, EndSession's sweep skips it),
    // but even if one were ever created directly, the report must still
    // never surface it against this event's scoped totals.
    \App\Models\AttendanceRecord::create([
        'session_id' => $session->id, 'student_id' => $bsbaStudent->id,
        'status' => 'present',
    ]);

    $report = (new BuildMasterReport)();

    expect($report['events'][0]['present'])->toBe(1);
});

it('scopes counts to both the viewer\'s own department and the event\'s department scope together', function () {
    $bsit = masterReportDept('BSIT');
    $bed = masterReportDept('BED');

    // Event open to BSIT + BEd, but the viewer is an SC Admin scoped to
    // BSIT only — the report should reflect the intersection of the two,
    // i.e. only BSIT students, even though BEd is also in the event's
    // own scope.
    $event = masterReportEvent('BSIT + BEd Event', [$bsit->id, $bed->id]);
    masterReportSession($event);

    masterReportStudent('2023100008', 'BSIT');
    masterReportStudent('2023100009', 'BED');

    $report = (new BuildMasterReport)($bsit->id);

    expect($report['events'][0]['absent'])->toBe(1);
});

it('spans every event in the semester, each with its own independent department scope', function () {
    $bsit = masterReportDept('BSIT');
    $bsba = masterReportDept('BSBA');

    $bsitOnlyEvent = masterReportEvent('BSIT-only Event', [$bsit->id]);
    masterReportSession($bsitOnlyEvent);
    $openEvent = masterReportEvent('Open Event');
    masterReportSession($openEvent);

    masterReportStudent('2023100010', 'BSIT');
    masterReportStudent('2023100011', 'BSBA');

    $report = (new BuildMasterReport)();
    $byName = collect($report['events'])->keyBy('name');

    // BSIT-only event: only the BSIT student was ever eligible.
    expect($byName['BSIT-only Event']['absent'])->toBe(1)
        // Open event: both students are eligible, unrestricted.
        ->and($byName['Open Event']['absent'])->toBe(2);
});
