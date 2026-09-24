<?php

namespace App\Application\Actions\Exclusions;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\TooManyImportRowsException;
use App\Imports\ExclusionsImport;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * student-exclusion-feature-plan.md §5 (bulk path) / §5's CSV format /
 * §7 ("bulk upload preview always shown before commit — no silent bulk
 * actions") / §9.5's still-open bulk-*removal* question is untouched by
 * this class, which only handles bulk *creation*.
 *
 * The CSV itself carries only student + scope info — student_id, scope
 * (EVENT|DAY|WINDOW, case-insensitive), day (day_number, required for
 * DAY/WINDOW), window (Morning/Afternoon/Evening, required for WINDOW
 * only). One reason is supplied once in the upload form and applies to
 * every row in the batch (§5 step 3) — it is never a column in the file.
 *
 * Every valid row is created through CreateExclusion itself, not a
 * parallel write path — so a bulk row obeys the exact same rules
 * (student existence, target-not-ended, duplicate-active check, atomic
 * per-row locking) as a single manual add. This mirrors how
 * BulkImportStudents reuses CreateStudent per row.
 */
final class BulkCreateExclusions
{
    /** Same reasoning as BulkImportStudents::MAX_ROWS_PER_FILE. */
    public const MAX_ROWS = 1000;

    /**
     * Validates every row and reports what a real bulk-exclude would do
     * — row by row — without writing anything to the database. Backs
     * the plan's required preview screen (§5 step 5, §7).
     *
     * @return array{
     *     total_rows: int, valid: int, invalid: int,
     *     rows: array<int, array{
     *         row: int, valid: bool, reasons: array<int, string>,
     *         student_number: ?string, scope: ?string, day: ?int, window: ?string,
     *     }>,
     * }
     */
    public function preview(UploadedFile $file, EventModel $event, Student $actor): array
    {
        return $this->process($file, $event, $actor, reason: null, commit: false);
    }

    /**
     * @return array{
     *     batch_id: string, total_rows: int, excluded: int, failed: int,
     *     errors: array<int, array{row: int, student_number: ?string, reasons: array<int, string>}>,
     * }
     */
    public function __invoke(UploadedFile $file, EventModel $event, string $reason, Student $actor): array
    {
        $batchId = (string) Str::uuid();
        $result = $this->process($file, $event, $actor, $reason, commit: true, batchId: $batchId);

        $errors = [];
        foreach ($result['rows'] as $row) {
            if (! $row['valid']) {
                $errors[] = [
                    'row' => $row['row'],
                    'student_number' => $row['student_number'],
                    'reasons' => $row['reasons'],
                ];
            }
        }

        return [
            'batch_id' => $batchId,
            'total_rows' => $result['total_rows'],
            'excluded' => $result['valid'],
            'failed' => $result['invalid'],
            'errors' => $errors,
        ];
    }

    /**
     * @return array{total_rows: int, valid: int, invalid: int, rows: array}
     */
    private function process(
        UploadedFile $file,
        EventModel $event,
        Student $actor,
        ?string $reason,
        bool $commit,
        ?string $batchId = null,
    ): array {
        $import = new ExclusionsImport;
        Excel::import($import, $file);
        $rows = $import->rows;

        if ($rows->count() > self::MAX_ROWS) {
            throw new TooManyImportRowsException(self::MAX_ROWS, $rows->count());
        }

        // §6a point 2: "the ended/active check must happen atomically at
        // commit time on the server, not based on what the UI displayed
        // when the form was opened." Read straight from the database
        // rather than off the caller's EventModel instance — an event
        // model that was just created (or hydrated from a request
        // payload) carries no `status` at all, because the column is
        // defaulted by the database, and dereferencing that null was
        // what used to blow this whole batch up on the first EVENT-scope
        // row. Every committed row is re-checked inside
        // CreateExclusion's own locked transaction anyway; this pass
        // exists so the preview can report the reason per row instead.
        //
        // No string cast here: `status` is cast to EventStatus on the
        // model, and Eloquent applies casts to ->value() too, so this
        // already comes back as an EventStatus (or null for a row that
        // somehow has none) and compares directly.
        $eventHasEnded = EventModel::whereKey($event->id)->value('status') === EventStatus::Ended;

        // Same-batch duplicate guard, mirrors BulkImportStudents'
        // $seenNumbers — two rows in one file targeting the exact same
        // student+scope is still a duplicate, even before either commits.
        $seenTargets = [];
        $rowResults = [];
        $totalValid = 0;

        foreach ($rows as $index => $row) {
            $lineNumber = $index + 2; // heading row is row 1

            $studentNumber = trim((string) ($row['student_id'] ?? ''));
            $scopeRaw = trim((string) ($row['scope'] ?? ''));
            $dayRaw = trim((string) ($row['day'] ?? ''));
            $windowRaw = trim((string) ($row['window'] ?? ''));

            $reasons = [];
            $student = $studentNumber !== '' ? Student::where('student_number', $studentNumber)->first() : null;

            if ($studentNumber === '') {
                $reasons[] = 'Missing student_id';
            } elseif (! $student) {
                $reasons[] = 'No student found with this student_id';
            }

            $scope = ExclusionScope::tryFrom(strtolower($scopeRaw));
            if ($scopeRaw === '') {
                $reasons[] = 'Missing scope';
            } elseif (! $scope) {
                $reasons[] = "Invalid scope '{$scopeRaw}' — must be EVENT, DAY, or WINDOW";
            }

            $eventDay = null;
            $windowType = null;

            if ($scope !== null && $scope !== ExclusionScope::Event) {
                if ($dayRaw === '' || ! ctype_digit($dayRaw)) {
                    $reasons[] = 'A valid day number is required for DAY/WINDOW scope';
                } else {
                    $eventDay = EventDay::where('event_id', $event->id)
                        ->where('day_number', (int) $dayRaw)
                        ->first();

                    if (! $eventDay) {
                        $reasons[] = "Day {$dayRaw} does not exist on this event yet";
                    }
                }

                if ($scope === ExclusionScope::Window) {
                    $windowType = WindowType::tryFrom(strtolower($windowRaw));

                    if ($windowRaw === '') {
                        $reasons[] = 'A window (Morning/Afternoon/Evening) is required for WINDOW scope';
                    } elseif (! $windowType) {
                        $reasons[] = "Invalid window '{$windowRaw}'";
                    } elseif ($eventDay && ! $eventDay->hasWindow($windowType)) {
                        $reasons[] = "Day {$dayRaw} has no {$windowRaw} window on this event";
                    }
                }
            }

            // Ended-schedule check — same rule CreateExclusion enforces
            // for a single add (§2 rule 4), applied per row here so the
            // preview can report it before commit rather than aborting
            // the whole batch on the first bad row.
            if ($reasons === []) {
                if ($scope === ExclusionScope::Event && $eventHasEnded) {
                    $reasons[] = 'This event has already ended';
                } elseif ($scope === ExclusionScope::Day && $eventDay?->hasEnded()) {
                    $reasons[] = "Day {$dayRaw} has already ended";
                } elseif ($scope === ExclusionScope::Window && $eventDay && $windowType && $eventDay->windowHasEnded($windowType)) {
                    $reasons[] = "Day {$dayRaw} {$windowRaw} has already ended";
                }
            }

            // Duplicate-within-batch + duplicate-active-in-DB checks.
            if ($reasons === [] && $student) {
                $targetKey = implode('|', [$student->id, $scope->value, $eventDay?->id, $windowType?->value]);

                if (isset($seenTargets[$targetKey])) {
                    $reasons[] = 'Duplicate target within this batch';
                } elseif (Exclusion::hasActiveDuplicate($student->id, $event->id, [
                    'scope' => $scope,
                    'event_day_id' => $eventDay?->id,
                    'window_type' => $windowType,
                ])) {
                    $reasons[] = 'This student already has an active exclusion for this exact scope';
                }
            }

            $isValid = $reasons === [];

            if ($isValid) {
                $seenTargets[implode('|', [$student->id, $scope->value, $eventDay?->id, $windowType?->value])] = true;
            }

            if ($isValid && $commit) {
                try {
                    (new CreateExclusion)([
                        'event_id' => $event->id,
                        'scope' => $scope->value,
                        'event_day_id' => $eventDay?->id,
                        'window_type' => $windowType?->value,
                        'reason' => $reason,
                        'student_number' => $studentNumber,
                        'batch_id' => $batchId,
                    ], $actor);
                } catch (Throwable $e) {
                    // A row can still fail here despite passing the checks
                    // above — e.g. a concurrent exclusion created by a
                    // different request between validation and this
                    // write. Recorded as a normal per-row failure, same
                    // reasoning as BulkImportStudents.
                    $isValid = false;
                    $reasons = ['Could not be created: '.$e->getMessage()];
                }
            }

            $rowResults[] = [
                'row' => $lineNumber,
                'valid' => $isValid,
                'reasons' => $reasons,
                'student_number' => $studentNumber ?: null,
                'scope' => $scope?->value,
                'day' => $eventDay?->day_number,
                'window' => $windowType?->value,
            ];

            $totalValid += (int) $isValid;
        }

        return [
            'total_rows' => count($rowResults),
            'valid' => $totalValid,
            'invalid' => count($rowResults) - $totalValid,
            'rows' => $rowResults,
        ];
    }
}
