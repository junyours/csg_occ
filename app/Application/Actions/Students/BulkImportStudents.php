<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\NoActiveSemesterException;
use App\Domain\Exceptions\TooManyImportRowsException;
use App\Domain\Support\CurrentSemester;
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
            'imported' => $result['created'] + $result['updated'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'failed' => $result['invalid'],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function process(array $files, Student $actor, bool $commit): array
    {
        // Never import without an active semester (preview included).
        $currentSemesterId = CurrentSemester::id()
            ?? throw new NoActiveSemesterException('There is no active semester to import students into.');

        $departments = Department::all();
        $departmentsByCode = $departments->keyBy(fn ($d) => strtoupper($d->code));
        $departmentsById = $departments->keyBy('id');

        // Student numbers already committed to earlier in *this same*
        // batch — a duplicate across two different section files in one
        // upload is still a duplicate, not two independent imports.
        $seenNumbers = [];
        $fileResults = [];
        $totalRows = 0;
        $totalValid = 0;
        $totals = ['create' => 0, 'update' => 0, 'skip' => 0];

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

            $numbersInFile = $rows
                ->map(fn ($row) => trim((string) ($row['id_number'] ?? $row['student_number'] ?? '')))
                ->filter(fn (string $number) => $number !== '')
                ->unique()->values()->all();
            $existingByNumber = $numbersInFile === []
                ? collect()
                : Student::whereIn('student_number', $numbersInFile)->get()->keyBy('student_number');

            foreach ($rows as $index => $row) {
                // Row 1 is the heading row, so the first data row is 2.
                $lineNumber = $index + 2;
                $studentNumber = trim((string) ($row['id_number'] ?? $row['student_number'] ?? ''));
                $existing = $studentNumber !== '' ? $existingByNumber->get($studentNumber) : null;

                $reasons = $this->validateRow($row, $studentNumber, $seenNumbers, $existing);
                $isValid = $reasons === [];
                $action = null;
                $change = null;

                if ($isValid) {
                    $seenNumbers[$studentNumber] = true;

                    // Upsert: unknown -> create; already in the current
                    // semester AND active -> skip; otherwise update + activate.
                    if ($existing === null) {
                        $action = 'create';
                    } elseif ((int) $existing->semester_id === $currentSemesterId && ! $existing->isInactive()) {
                        $action = 'skip';
                    } else {
                        $action = 'update';
                        $change = $this->describeChange($existing, $department, $section, $departmentsById);
                    }
                }

                $dateEnrolled = $this->parseDate($row['date_enrolled'] ?? null);

                if ($isValid && $commit && $action !== 'skip') {
                    try {
                        if ($action === 'update') {
                            (new ReenrollStudent)($existing, [
                                'department_id' => $department->id,
                                'major' => $section->major,
                                'year_level' => $section->yearLevel,
                                'section' => $section->section,
                                'date_enrolled' => $dateEnrolled,
                            ]);
                        } else {
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
                        }
                    } catch (Throwable $e) {
                        // e.g. a concurrent import created the same number.
                        $isValid = false;
                        $action = null;
                        $change = null;
                        $reasons = ['Could not be created: '.$e->getMessage()];
                    }
                }

                if ($action !== null) {
                    $totals[$action]++;
                }

                $rowResults[] = [
                    'row' => $lineNumber,
                    'valid' => $isValid,
                    'reasons' => $reasons,
                    'action' => $action,
                    'change' => $change,
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
            'created' => $totals['create'],
            'updated' => $totals['update'],
            'skipped' => $totals['skip'],
            'files' => $fileResults,
        ];
    }

    /**
     * @param  array<string, bool>  $seenNumbers
     * @return array<int, string>
     */
    private function validateRow(Collection $row, string $studentNumber, array $seenNumbers, ?Student $existing): array
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

        if ($reasons !== []) {
            return $reasons;
        }

        if (isset($seenNumbers[$studentNumber])) {
            $reasons[] = 'Duplicate student ID within this batch';
        } elseif ($existing?->role === Role::SystemAdmin) {
            $reasons[] = 'System Admin account cannot be imported/changed here';
        }

        return $reasons;
    }

    /** "BSIT-2A → BSBA-FM-3H" when placement changes; null when it doesn't. */
    private function describeChange(Student $existing, Department $department, SectionFilename $section, Collection $departmentsById): ?string
    {
        $unchanged = (int) $existing->department_id === (int) $department->id
            && ($existing->major ?? null) === $section->major
            && ($existing->year_level ?? null) === $section->yearLevel
            && ($existing->section ?? null) === $section->section;

        if ($unchanged) {
            return null;
        }

        $label = fn (?string $code, ?string $major, ?string $sectionName, ?string $yearLevel) => ($code ?? '—')
            .($major ? '-'.$major : '')
            .'-'.($sectionName ?: ($yearLevel ?: '—'));

        return $label($departmentsById->get($existing->department_id)?->code, $existing->major, $existing->section, $existing->year_level)
            .' → '
            .$label($department->code, $section->major, $section->section, $section->yearLevel);
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
