<?php

use App\Application\Actions\Students\BulkImportStudents;
use App\Domain\Exceptions\TooManyImportRowsException;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bulkImportDept(string $code): Department
{
    return Department::firstOrCreate(['code' => $code], ['name' => $code]);
}

function bulkImportActor(string $role, ?int $scAdminDeptId = null): Student
{
    return Student::create([
        'student_number' => '2020000001',
        'last_name' => 'Admin', 'first_name' => 'Test',
        'department_id' => bulkImportDept('CCS')->id,
        'username' => 'actor'.uniqid(),
        'password' => 'password',
        'role' => $role,
        'sc_admin_department_id' => $scAdminDeptId,
    ]);
}

/**
 * Builds a plain-CSV upload named after a *section* (e.g.
 * "BSIT-1A.csv") — Maatwebsite\Excel reads csv/xlsx/xls identically once
 * past the file-format layer, and a CSV is far simpler to hand-construct
 * in a test than a real xlsx binary. Course/major/year/section are read
 * from $filename, not from any column in the sheet — see
 * SectionFilename.
 */
function bulkImportFile(string $filename, array $rows, array $headers = ['id_number', 'last_name', 'first_name', 'middle_name', 'date_enrolled']): UploadedFile
{
    $lines = [implode(',', $headers)];

    foreach ($rows as $row) {
        $lines[] = implode(',', array_map(fn ($v) => (string) ($v ?? ''), $row));
    }

    return UploadedFile::fake()->createWithContent($filename, implode("\n", $lines));
}

it('imports every valid row and creates real student records, reading course/major/year/section from the filename', function () {
    bulkImportDept('BSBA');
    $actor = bulkImportActor('csg_admin');

    $file = bulkImportFile('BSBA-FM-1H.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', '2023-06-05'],
        ['2023000002', 'Reyes', 'Ana', 'Santos', ''],
    ]);

    $report = (new BulkImportStudents)([$file], $actor);

    expect($report['total_files'])->toBe(1)
        ->and($report['total_rows'])->toBe(2)
        ->and($report['imported'])->toBe(2)
        ->and($report['failed'])->toBe(0)
        ->and($report['errors'])->toBe([]);

    $first = Student::where('student_number', '2023000001')->firstOrFail();
    expect($first->department->code)->toBe('BSBA')
        ->and($first->major)->toBe('FM')
        ->and($first->year_level)->toBe('1')
        ->and($first->section)->toBe('1H')
        ->and($first->date_enrolled?->format('Y-m-d'))->toBe('2023-06-05');

    $second = Student::where('student_number', '2023000002')->firstOrFail();
    expect($second->date_enrolled)->toBeNull();
});

it('imports a course with no major segment (e.g. BEED/BSIT) leaving major null', function () {
    bulkImportDept('BSIT');
    $actor = bulkImportActor('csg_admin');

    $file = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    (new BulkImportStudents)([$file], $actor);

    $student = Student::where('student_number', '2023000001')->firstOrFail();
    expect($student->department->code)->toBe('BSIT')
        ->and($student->major)->toBeNull()
        ->and($student->section)->toBe('1A');
});

it('reports a missing required field without importing that row, but still imports the rest', function () {
    bulkImportDept('BSIT');
    $actor = bulkImportActor('csg_admin');

    $file = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', '', 'Juan', 'Dela', ''], // missing last_name
        ['2023000002', 'Reyes', 'Ana', 'Santos', ''],
    ]);

    $report = (new BulkImportStudents)([$file], $actor);

    expect($report['imported'])->toBe(1)
        ->and($report['failed'])->toBe(1)
        ->and($report['errors'][0]['row'])->toBe(2)
        ->and($report['errors'][0]['filename'])->toBe('BSIT-1A.csv')
        ->and($report['errors'][0]['reasons'])->toContain('Missing required field: last_name');

    expect(Student::where('student_number', '2023000001')->exists())->toBeFalse();
});

it('rejects a file whose name has no course/major/year/section shape', function () {
    $actor = bulkImportActor('csg_admin');

    $file = bulkImportFile('random-notes.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    $report = (new BulkImportStudents)([$file], $actor);

    expect($report['total_files'])->toBe(1)
        ->and($report['total_rows'])->toBe(0)
        ->and($report['imported'])->toBe(0)
        ->and($report['failed'])->toBe(0);
});

it('reports an unknown department code parsed from the filename', function () {
    $actor = bulkImportActor('csg_admin');

    $file = bulkImportFile('NOPE-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    $preview = (new BulkImportStudents)->preview([$file], $actor);

    expect($preview['files'][0]['valid'])->toBeFalse()
        ->and($preview['files'][0]['parse_error'])->toContain('Unknown department code: NOPE');
});

it('flags a duplicate student number across two files in the same batch, keeping the first occurrence', function () {
    bulkImportDept('BSIT');
    $actor = bulkImportActor('csg_admin');

    $fileA = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);
    $fileB = bulkImportFile('BSIT-1B.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    $report = (new BulkImportStudents)([$fileA, $fileB], $actor);

    expect($report['imported'])->toBe(1)
        ->and($report['failed'])->toBe(1)
        ->and($report['errors'][0]['filename'])->toBe('BSIT-1B.csv')
        ->and($report['errors'][0]['reasons'])->toContain('Duplicate student ID within this batch');
});

it('flags a student number that already exists in the database', function () {
    $bsit = bulkImportDept('BSIT');
    $actor = bulkImportActor('csg_admin');
    Student::create([
        'student_number' => '2023000001', 'last_name' => 'Existing', 'first_name' => 'Student',
        'department_id' => $bsit->id, 'username' => 'existing', 'password' => 'password',
    ]);

    $file = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);

    $report = (new BulkImportStudents)([$file], $actor);

    expect($report['failed'])->toBe(1)
        ->and($report['errors'][0]['reasons'])->toContain('Student ID already exists');
});

it('lets a csg admin import across multiple departments in one batch', function () {
    bulkImportDept('BSIT');
    bulkImportDept('BEED');
    $actor = bulkImportActor('csg_admin');

    $fileA = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);
    $fileB = bulkImportFile('BEED-1A.csv', [
        ['2023000002', 'Reyes', 'Ana', 'Santos', ''],
    ]);

    $report = (new BulkImportStudents)([$fileA, $fileB], $actor);

    expect($report['total_files'])->toBe(2)
        ->and($report['imported'])->toBe(2)->and($report['failed'])->toBe(0);
});

it('flags a whole file outside an sc admin\'s administered department, but still imports files within it', function () {
    $bsit = bulkImportDept('BSIT');
    bulkImportDept('BEED');
    $actor = bulkImportActor('sc_admin', $bsit->id);

    $fileA = bulkImportFile('BSIT-1A.csv', [
        ['2023000001', 'Cruz', 'Juan', 'Dela', ''],
    ]);
    $fileB = bulkImportFile('BEED-1A.csv', [
        ['2023000002', 'Reyes', 'Ana', 'Santos', ''],
    ]);

    $report = (new BulkImportStudents)([$fileA, $fileB], $actor);

    expect($report['imported'])->toBe(1)
        ->and($report['failed'])->toBe(0)
        ->and($report['total_files'])->toBe(2);

    $preview = (new BulkImportStudents)->preview([$fileB], $actor);
    expect($preview['files'][0]['valid'])->toBeFalse()
        ->and($preview['files'][0]['parse_error'])->toContain('Outside your administered department');

    expect(Student::where('student_number', '2023000001')->exists())->toBeTrue()
        ->and(Student::where('student_number', '2023000002')->exists())->toBeFalse();
});

it('throws when a single file has more data rows than the synchronous import cap', function () {
    bulkImportDept('BSIT');
    $actor = bulkImportActor('csg_admin');

    $rows = [];
    for ($i = 1; $i <= BulkImportStudents::MAX_ROWS_PER_FILE + 1; $i++) {
        $rows[] = [sprintf('2023%06d', $i), 'Cruz', 'Juan', 'Dela', ''];
    }
    $file = bulkImportFile('BSIT-1A.csv', $rows);

    (new BulkImportStudents)([$file], $actor);
})->throws(TooManyImportRowsException::class);
