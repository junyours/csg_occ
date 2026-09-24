<?php

namespace App\Application\Actions\Reports;

use App\Domain\Contracts\RosterPdfRendererInterface;
use App\Domain\Enums\ReportGenerationStatus;
use App\Exports\MasterRosterReportExport;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * The master-report counterpart to ProcessRosterReportGeneration: same
 * "runs entirely after the response, real progress via processed_steps"
 * shape, just building/rendering the combined multi-event report
 * instead of one event's.
 */
final class ProcessMasterReportGeneration
{
    public function __construct(
        private readonly BuildMasterRosterReport $buildMasterRosterReport,
        private readonly RosterPdfRendererInterface $pdfRenderer,
    ) {}

    public function __invoke(ReportGeneration $generation): void
    {
        try {
            $generation->update(['status' => ReportGenerationStatus::Processing]);

            $eventIds = $generation->event_ids ?? [];
            $events = EventModel::whereIn('id', $eventIds)->get()->sortBy(
                fn (EventModel $e) => array_search($e->id, $eventIds, true)
            )->values();

            $report = ($this->buildMasterRosterReport)(
                $events,
                $generation->department_id,
                $generation->major,
                $generation->year_level,
                $generation->section,
                onGroupBuilt: function () use ($generation) {
                    $generation->increment('processed_steps');
                },
            );

            [$path, $filename] = $generation->format === 'pdf'
                ? $this->writePdf($generation, $report)
                : $this->writeExcel($generation, $report);

            $generation->update([
                'status' => ReportGenerationStatus::Completed,
                'file_path' => $path,
                'file_name' => $filename,
                'processed_steps' => $generation->total_steps,
            ]);
        } catch (Throwable $e) {
            $generation->update([
                'status' => ReportGenerationStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Master report generation failed', [
                'report_generation_id' => $generation->id,
                'event_ids' => $generation->event_ids,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [storage path, download filename]
     */
    private function writePdf(ReportGeneration $generation, array $report): array
    {
        $bytes = $this->pdfRenderer->renderMaster($report);

        $filename = 'master-roster-report.pdf';
        $path = "report-generations/{$generation->id}/{$filename}";

        Storage::disk($generation->file_disk)->put($path, $bytes);

        $generation->increment('processed_steps');

        return [$path, $filename];
    }

    /**
     * @return array{0: string, 1: string} [storage path, download filename]
     */
    private function writeExcel(ReportGeneration $generation, array $report): array
    {
        $filename = 'master-roster-report.xlsx';
        $path = "report-generations/{$generation->id}/{$filename}";

        $export = new MasterRosterReportExport(
            $report,
            onSheetWritten: function () use ($generation) {
                $generation->increment('processed_steps');
            },
        );

        Excel::store($export, $path, $generation->file_disk);

        $generation->increment('processed_steps');

        return [$path, $filename];
    }
}
