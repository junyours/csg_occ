<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\TooManyImportRowsException;
use App\Domain\ValueObjects\SectionFilename;
use App\Imports\StudentsImport;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The new section-file workflow: one spreadsheet = one section, and the
 * section's course/major/year level is read from the *filename*
 * ("BSBA-FM-1H.xlsx"), not from columns in the sheet — see
 * SectionFilename. Every uploaded file only needs five columns now
 * (ID Number, Last Name, First Name, Middle Name, Date Enrolled), so the
 * upload accepts a batch of files at once (one per section) instead of
 * one big spreadsheet with a department_code/year_level/section column
 * per row.
 */
final class BulkImportStudents
{
    /**
     * Spec §12: no queue on shared hosting, so this all runs inside one
     * request. A single section file is realistically never anywhere
     * near this size, but the cap still exists per-file as a safety net
     * — a mis-named file being read as "one section" shouldn't be able
     * to hang the request either.
     */
    public const MAX_ROWS_PER_FILE = 1000;

    /**
     * Validates every file in the batch and reports what a real import
     * would do — file by file, row by row — without writing anything to
     * the database.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{
     *     total_files: int,
     *     total_rows: int,
     *     valid: int,
     *     invalid: int,
     *     files: array<int, array{
     *         filename: string, valid: bool, parse_error: ?string,
     *         department_code: ?string, major: ?string, year_level: ?string, section: ?string,
     *         rows: array<int, array{
     *             row: int, valid: bool, reasons: array<int, string>,
     *             student_number: ?string, last_name: ?string, first_name: ?string,
     *             middle_name: ?string, date_enrolled: ?string,
     *         }>,
     *     }>,
     * }
     */
    public function preview(array $files, Student $actor): array
    {
        return $this->process($files, $actor, commit: false);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{
     *     total_files: int,
     *     total_rows: int,
     *     imported: int,
     *     failed: int,
     *     errors: array<int, array{filename: string, row: int, student_number: ?string, reasons: array<int, string>}>,
     * }
     */
    public function __invoke(array $files, Student $actor): array
    {
        $result = $this->process($files, $actor, commit: true);

        $errors = [];
        foreach ($result['files'] as $file) {
            foreach ($file['rows'] as $row) {
                if (! $row['valid']) {
                    $errors[] = [
                        'filename' => $file['filename'],
                        'row' => $row['row'],
                        'student_number' => $row['student_number'],
                        'reasons' => $row['reasons'],
                    ];
                }
            }
        }

        return [
            'total_files' => $result['total_files'],
            'total_rows' => $result['total_rows'],
            'imported' => $result['valid'],
            'failed' => $result['invalid'],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function process(array $files, Student $actor, bool $commit): array
    {
        $departmentsByCode = Department::all()->keyBy(fn ($d) => strtoupper($d->code));

        // Student numbers already committed to earlier in *this same*
        // batch — a duplicate across two different section files in one
        // upload is still a duplicate, not two independent imports.
        $seenNumbers = [];
        $fileResults = [];
        $totalRows = 0;
        $totalValid = 0;

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName();

            try {
                $section = SectionFilename::parse($filename);
            } catch (InvalidArgumentException $e) {
                $fileResults[] = [
                    'filename' => $filename,
                    'valid' => false,
                    'parse_error' => $e->getMessage(),
                    'department_code' => null,
                    'major' => null,
                    'year_level' => null,
                    'section' => null,
                    'rows' => [],
                ];

                continue;
            }

            $department = $departmentsByCode->get($section->courseCode);

            if ($department === null) {
                $fileResults[] = [
                    'filename' => $filename,
                    'valid' => false,
                    'parse_error' => "Unknown department code: {$section->courseCode}",
                    'department_code' => $section->courseCode,
                    'major' => $section->major,
                    'year_level' => $section->yearLevel,
                    'section' => $section->section,
                    'rows' => [],
                ];

                continue;
            }

            if (! $actor->can('create', [Student::class, $department->id])) {
                $fileResults[] = [
                    'filename' => $filename,
                    'valid' => false,
                    'parse_error' => $actor->role === Role::ScAdmin
                        ? "Outside your administered department ({$section->courseCode})"
                        : 'Not authorized to add students to this department',
                    'department_code' => $section->courseCode,
                    'major' => $section->major,
                    'year_level' => $section->yearLevel,
                    'section' => $section->section,
                    'rows' => [],
                ];

                continue;
            }

            $import = new StudentsImport;
            Excel::import($import, $file);
            $rows = $import->rows;

            if ($rows->count() > self::MAX_ROWS_PER_FILE) {
                throw new TooManyImportRowsException(self::MAX_ROWS_PER_FILE, $rows->count());
            }

            $rowResults = [];

            foreach ($rows as $index => $row) {
                // Row 1 is the heading row, so the first data row is 2 —
                // matching the row number a person would see if they
                // opened the file in Excel and needed to go fix
                // something.
                $lineNumber = $index + 2;
                $studentNumber = trim((string) ($row['id_number'] ?? $row['student_number'] ?? ''));

                $reasons = $this->validateRow($row, $studentNumber, $seenNumbers);
                $isValid = $reasons === [];

                if ($isValid) {
                    $seenNumbers[$studentNumber] = true;
                }

                $dateEnrolled = $this->parseDate($row['date_enrolled'] ?? null);

                if ($isValid && $commit) {
                    try {
                        (new CreateStudent)([
                            'student_number' => $studentNumber,
                            'last_name' => trim((string) $row['last_name']),
                            'first_name' => trim((string) $row['first_name']),
                            'middle_name' => $this->nullableTrim($row['middle_name'] ?? null),
                            'department_id' => $department->id,
                            'major' => $section->major,
                            'year_level' => $section->yearLevel,
                            'section' => $section->section,
                            'date_enrolled' => $dateEnrolled,
                        ]);
                    } catch (Throwable $e) {
                        // A row can still fail here despite passing
                        // validateRow() — e.g. a duplicate student_number
                        // created by a *different concurrent* import
                        // between our upfront check and this insert.
                        // Recorded as a normal per-row failure rather
                        // than aborting the rest of the batch.
                        $isValid = false;
                        $reasons = ['Could not be created: '.$e->getMessage()];
                    }
                }

                $rowResults[] = [
                    'row' => $lineNumber,
                    'valid' => $isValid,
                    'reasons' => $reasons,
                    'student_number' => $studentNumber ?: null,
                    'last_name' => $this->nullableTrim($row['last_name'] ?? null),
                    'first_name' => $this->nullableTrim($row['first_name'] ?? null),
                    'middle_name' => $this->nullableTrim($row['middle_name'] ?? null),
                    'date_enrolled' => $dateEnrolled,
                ];
            }

            $validInFile = count(array_filter($rowResults, fn (array $r) => $r['valid']));
            $totalRows += count($rowResults);
            $totalValid += $validInFile;

            $fileResults[] = [
                'filename' => $filename,
                'valid' => true,
                'parse_error' => null,
                'department_code' => $section->courseCode,
                'major' => $section->major,
                'year_level' => $section->yearLevel,
                'section' => $section->section,
                'rows' => $rowResults,
            ];
        }

        return [
            'total_files' => count($fileResults),
            'total_rows' => $totalRows,
            'valid' => $totalValid,
            'invalid' => $totalRows - $totalValid,
            'files' => $fileResults,
        ];
    }

    /**
     * @param  array<string, bool>  $seenNumbers
     * @return array<int, string>
     */
    private function validateRow(Collection $row, string $studentNumber, array $seenNumbers): array
    {
        $reasons = [];

        foreach (['last_name' => $row['last_name'] ?? null, 'first_name' => $row['first_name'] ?? null] as $field => $value) {
            if (trim((string) $value) === '') {
                $reasons[] = "Missing required field: {$field}";
            }
        }

        if ($studentNumber === '') {
            $reasons[] = 'Missing required field: id_number';
        }

        // Every reason below needs a real student_number to check
        // against, so stop here rather than reporting confusing
        // secondary errors off an already-missing field.
        if ($reasons !== []) {
            return $reasons;
        }

        if (isset($seenNumbers[$studentNumber])) {
            $reasons[] = 'Duplicate student ID within this batch';
        } elseif (Student::where('student_number', $studentNumber)->exists()) {
            $reasons[] = 'Student ID already exists';
        }

        return $reasons;
    }

    /**
     * Handles a date cell coming back either as an Excel serial number
     * (the common case for a genuine date-formatted cell) or as plain
     * text (a CSV, or a text-formatted cell) — returns null rather than
     * throwing on anything unparseable, since date_enrolled is optional.
     */
    private function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
