<?php

namespace App\Application\Actions\Reports;

use App\Domain\Enums\ReportGenerationStatus;
use App\Domain\Enums\Role;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use App\Models\Student;

final class StartRosterReportGeneration
{
    public function __construct(
        private readonly ProcessRosterReportGeneration $processRosterReportGeneration,
    ) {}

    /**
     * Creates the tracking row (fast — a handful of counting queries,
     * never the full roster build) and schedules the real work for
     * after the response is sent. dispatch(...)->afterResponse() runs
     * the closure in this same PHP-FPM worker once the response has
     * already been flushed to the browser (via fastcgi_finish_request()
     * where the host supports it — effectively every shared host on
     * PHP-FPM does), so a full-school export no longer ties up the HTTP
     * request/response cycle (and can't get killed by a webserver/proxy
     * timeout that a bigger set_time_limit() can't touch) — this is the
     * actual fix for "the PDF isn't generating", not just a progress bar
     * painted over the same blocking call.
     *
     * ->onConnection('sync') is explicit and deliberate: whatever
     * QUEUE_CONNECTION happens to be set to elsewhere, this dispatch
     * must never require a queue worker process, since the spec rules
     * that out for shared hosting.
     */
    public function __invoke(
        EventModel $event,
        Student $requester,
        string $format,
        ?int $departmentId,
        ?string $major,
        ?string $yearLevel,
        ?string $section,
    ): ReportGeneration {
        $format = $format === 'pdf' ? 'pdf' : 'xlsx';

        $generation = ReportGeneration::create([
            'event_id' => $event->id,
            'format' => $format,
            'department_id' => $departmentId,
            'major' => $major,
            'year_level' => $yearLevel,
            'section' => $section,
            'status' => ReportGenerationStatus::Pending,
            'total_steps' => $this->estimateTotalSteps($departmentId, $major, $yearLevel, $section, $format),
            'processed_steps' => 0,
            'requested_by' => $requester->id,
        ]);

        dispatch(function () use ($generation) {
            ($this->processRosterReportGeneration)($generation);
        })->onConnection('sync')->afterResponse();

        return $generation;
    }

    /**
     * One step per department/year-level/section group during the data
     * build, since BuildEventRosterReport's onGroupBuilt fires once per
     * group. For xlsx, a second pass over the same group count happens
     * while each Excel sheet is written (EventRosterGroupSheet's
     * onSheetWritten), plus one final step to save the finished file;
     * for pdf there's no per-group hook during rendering (dompdf builds
     * the whole document as one unit), so that whole phase is a single
     * step. See ProcessRosterReportGeneration for exactly what each
     * increment corresponds to.
     */
    private function estimateTotalSteps(?int $departmentId, ?string $major, ?string $yearLevel, ?string $section, string $format): int
    {
        $groupCount = max(1, Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when(filled($major), fn ($q) => $q->where('major', $major))
            ->when(filled($yearLevel), fn ($q) => $q->where('year_level', $yearLevel))
            ->when(filled($section), fn ($q) => $q->where('section', $section))
            ->select('department_id', 'major', 'year_level', 'section')
            ->distinct()
            ->get()
            ->count());

        return $format === 'xlsx'
            ? ($groupCount * 2) + 1
            : $groupCount + 1;
    }
}
