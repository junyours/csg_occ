<?php

namespace App\Application\Actions\Reports;

use App\Domain\Enums\ReportGenerationStatus;
use App\Domain\Enums\Role;
use App\Models\ReportGeneration;
use App\Models\Student;

/**
 * The master-report counterpart to StartRosterReportGeneration: same
 * "create the tracking row fast, do the real work after the response
 * via dispatch(...)->onConnection('sync')->afterResponse()" shape,
 * just for a report that spans several events instead of one.
 *
 * event_ids keeps every selected event, in the order the person picked
 * them — that order becomes the left-to-right column order in the
 * output (see BuildMasterRosterReport). event_id is still set, to the
 * first id in that list, purely to satisfy the existing not-null
 * foreign key on report_generations without needing a schema change —
 * every master-aware reader looks at event_ids, not event_id.
 */
final class StartMasterReportGeneration
{
    public function __construct(
        private readonly ProcessMasterReportGeneration $processMasterReportGeneration,
    ) {}

    /**
     * @param  Collection<int, int>|array<int, int>  $eventIds  In display order.
     */
    public function __invoke(
        iterable $eventIds,
        Student $requester,
        string $format,
        ?int $departmentId,
        ?string $major,
        ?string $yearLevel,
        ?string $section,
    ): ReportGeneration {
        $eventIds = collect($eventIds)->map(fn ($id) => (int) $id)->values();
        $format = $format === 'pdf' ? 'pdf' : 'xlsx';

        $generation = ReportGeneration::create([
            'event_id' => $eventIds->first(),
            'event_ids' => $eventIds->all(),
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
            ($this->processMasterReportGeneration)($generation);
        })->onConnection('sync')->afterResponse();

        return $generation;
    }

    /**
     * Same estimate math as StartRosterReportGeneration — one step per
     * distinct department/year-level/section group (twice for xlsx,
     * since each group also gets a sheet-written step), plus one final
     * step for saving the finished file. The roster is shared across
     * every selected event (see BuildMasterRosterReport), so the group
     * count doesn't change with the number of events — only the number
     * of session columns inside each group does.
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
