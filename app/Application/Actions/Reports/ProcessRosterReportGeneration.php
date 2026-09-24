<?php

namespace App\Application\Actions\Reports;

use App\Domain\Contracts\RosterPdfRendererInterface;
use App\Domain\Enums\ReportGenerationStatus;
use App\Exports\EventRosterReportExport;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

final class ProcessRosterReportGeneration
{
    public function __construct(
        private readonly BuildEventRosterReport $buildEventRosterReport,
        private readonly RosterPdfRendererInterface $pdfRenderer,
    ) {}

    /**
     * Runs entirely after the HTTP response for the "start generation"
     * request has already gone back to the browser (see
     * StartRosterReportGeneration) — nothing in here can make the
     * person's request hang, however big the roster is. Every
     * increment() below is a real completed unit of work, not a timer:
     * the frontend's progress bar is only ever as far along as this
     * method actually is.
     */
    public function __invoke(ReportGeneration $generation): void
    {
        try {
            $generation->update(['status' => ReportGenerationStatus::Processing]);

            $event = EventModel::findOrFail($generation->event_id);

            $report = ($this->buildEventRosterReport)(
                $event,
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

            // Snap to the true total rather than trusting the running
            // count to land exactly on it — estimateTotalSteps() counts
            // *distinct* groups up front from a plain student query,
            // while BuildEventRosterReport groups the roster it actually
            // loaded; the two are expected to agree, but completion
            // should never be blocked on floating step-counting matching
            // perfectly.
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

            Log::error('Roster report generation failed', [
                'report_generation_id' => $generation->id,
                'event_id' => $generation->event_id,
                'exception' => $e,
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [storage path, download filename]
     */
    private function writePdf(ReportGeneration $generation, array $report): array
    {
        $bytes = $this->pdfRenderer->render($report);

        $filename = Str::slug($report['event']['name'] ?: 'event').'-roster-report.pdf';
        $path = "report-generations/{$generation->id}/{$filename}";

        Storage::disk($generation->file_disk)->put($path, $bytes);

        // One step for the whole render+save — dompdf builds the
        // document as a single in-memory unit (see
        // DompdfRosterPdfRenderer's own docblock), so there's no
        // per-page hook to advance progress more finely than this.
        $generation->increment('processed_steps');

        return [$path, $filename];
    }

    /**
     * @return array{0: string, 1: string} [storage path, download filename]
     */
    private function writeExcel(ReportGeneration $generation, array $report): array
    {
        $filename = Str::slug($report['event']['name'] ?: 'event').'-roster-report.xlsx';
        $path = "report-generations/{$generation->id}/{$filename}";

        $export = new EventRosterReportExport(
            $report,
            onSheetWritten: function () use ($generation) {
                $generation->increment('processed_steps');
            },
        );

        Excel::store($export, $path, $generation->file_disk);

        // The one step that isn't per-sheet: turning the fully-built
        // workbook into actual bytes on disk happens once, after every
        // sheet above has already advanced the counter.
        $generation->increment('processed_steps');

        return [$path, $filename];
    }
}
