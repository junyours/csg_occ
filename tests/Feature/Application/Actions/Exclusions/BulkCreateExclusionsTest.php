<?php

use App\Application\Actions\Exclusions\BulkCreateExclusions;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use App\Models\Department;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

// Reuses the same student/event/day/session builders as
// CreateExclusionTest (redeclared here with a "bulk" prefix since Pest
// loads every test file's global function declarations into one shared
// namespace, and PHP doesn't allow declaring the same function twice).
function bulkExclusionDept(string $code = 'CS'): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function bulkExclusionAdmin(): Student
{
    return Student::create([
        'student_number' => '2020000099',
        'last_name' => 'Admin', 'first_name' => 'CSG',
        'department_id' => bulkExclusionDept('CCS')->id,
        'username' => 'bulkcsgadmin', 'password' => 'password',
        'role' => 'csg_admin',
    ]);
}

function bulkExclusionStudent(string $studentNumber): Student
{
    return Student::create([
        'student_number' => $studentNumber,
        'last_name' => 'Cruz', 'first_name' => 'Juan',
        'department_id' => bulkExclusionDept()->id,
        'username' => 'bulk'.$studentNumber,
        'password' => 'password',
        'qr_version' => 1,
    ]);
}

function bulkExclusionEvent(Student $creator): EventModel
{
    return EventModel::create(['name' => 'Bulk Test Event', 'created_by' => $creator->id]);
}

function bulkExclusionDay(EventModel $event, int $dayNumber, string $date = '2026-11-10'): EventDay
{
    return EventDay::create(['event_id' => $event->id, 'date' => $date, 'day_number' => $dayNumber]);
}

function bulkExclusionSession(EventDay $day, WindowType $windowType = WindowType::Morning, array $overrides = []): AttendanceSession
{
    return AttendanceSession::create(array_merge([
        'event_day_id' => $day->id,
        'window_type' => $windowType,
        'check_type' => CheckType::TimeIn,
        'start_time' => '07:00:00',
        'end_time' => '08:00:00',
        'grace_minutes' => 15,
        'status' => SessionStatus::Scheduled,
    ], $overrides));
}

/**
 * Builds a fake CSV UploadedFile matching student-exclusion-feature-
 * plan.md §5's format exactly: student_id | scope | day | window.
 * Same UploadedFile::fake()->createWithContent(...) convention as
 * StudentBulkImportControllerTest's bulkImportApiCsv().
 */
function bulkExclusionCsv(array $rows): UploadedFile
{
    $headers = ['student_id', 'scope', 'day', 'window'];
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => (string) ($v ?? ''), $row));
    }

    return UploadedFile::fake()->createWithContent('exclusions.csv', implode("\n", $lines));
}

it('commits a mixed batch of event/day/window rows and assigns a shared batch_id', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);
    $day2 = bulkExclusionDay($event, 2);
    bulkExclusionSession($day2, WindowType::Morning);

    $s1 = bulkExclusionStudent('2023100001');
    $s2 = bulkExclusionStudent('2023100002');
    $s3 = bulkExclusionStudent('2023100003');

    $csv = bulkExclusionCsv([
        [$s1->student_number, 'EVENT', '', ''],
        [$s2->student_number, 'DAY', '2', ''],
        [$s3->student_number, 'WINDOW', '2', 'Morning'],
    ]);

    $result = (new BulkCreateExclusions)($csv, $event, 'Disciplinary case #4821', $admin);

    expect($result['total_rows'])->toBe(3)
        ->and($result['excluded'])->toBe(3)
        ->and($result['failed'])->toBe(0)
        ->and($result['batch_id'])->not->toBeEmpty();

    $created = Exclusion::where('batch_id', $result['batch_id'])->get();
    expect($created)->toHaveCount(3)
        ->and($created->pluck('reason')->unique()->all())->toBe(['Disciplinary case #4821']);
});

it('rejects a row targeting a day that has already ended, without failing the whole batch', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);
    $day1 = bulkExclusionDay($event, 1);
    bulkExclusionSession($day1, WindowType::Morning, ['status' => SessionStatus::Ended, 'ended_at' => now()]);
    $day2 = bulkExclusionDay($event, 2, '2026-11-11');
    bulkExclusionSession($day2, WindowType::Morning);

    $good = bulkExclusionStudent('2023100010');
    $bad = bulkExclusionStudent('2023100011');

    $csv = bulkExclusionCsv([
        [$good->student_number, 'DAY', '2', ''],   // still open — should succeed
        [$bad->student_number, 'DAY', '1', ''],    // already ended — should fail
    ]);

    $result = (new BulkCreateExclusions)($csv, $event, 'Testing exclusion', $admin);

    expect($result['excluded'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['student_number'])->toBe($bad->student_number)
        ->and($result['errors'][0]['reasons'][0])->toContain('already ended');
});

it('rejects a row for a student_id that does not exist', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);

    $csv = bulkExclusionCsv([
        ['0000000000', 'EVENT', '', ''],
    ]);

    $result = (new BulkCreateExclusions)($csv, $event, 'Testing exclusion', $admin);

    expect($result['excluded'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'][0]['reasons'][0])->toContain('No student found');
});

it('rejects a duplicate target within the same batch, keeping only the first', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);
    $student = bulkExclusionStudent('2023100020');

    $csv = bulkExclusionCsv([
        [$student->student_number, 'EVENT', '', ''],
        [$student->student_number, 'EVENT', '', ''],
    ]);

    $result = (new BulkCreateExclusions)($csv, $event, 'Testing exclusion', $admin);

    expect($result['excluded'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'][0]['reasons'][0])->toBe('Duplicate target within this batch');
});

it('preview validates without writing anything to the database', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);
    $student = bulkExclusionStudent('2023100030');

    $csv = bulkExclusionCsv([
        [$student->student_number, 'EVENT', '', ''],
    ]);

    $result = (new BulkCreateExclusions)->preview($csv, $event, $admin);

    expect($result['valid'])->toBe(1)
        ->and(Exclusion::count())->toBe(0);
});

it('rejects an invalid scope value with a clear per-row reason', function () {
    $admin = bulkExclusionAdmin();
    $event = bulkExclusionEvent($admin);
    $student = bulkExclusionStudent('2023100040');

    $csv = bulkExclusionCsv([
        [$student->student_number, 'MONTH', '', ''],
    ]);

    $result = (new BulkCreateExclusions)($csv, $event, 'Testing exclusion', $admin);

    expect($result['failed'])->toBe(1)
        ->and($result['errors'][0]['reasons'][0])->toContain('Invalid scope');
});
