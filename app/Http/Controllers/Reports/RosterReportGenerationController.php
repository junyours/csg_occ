<?php

namespace App\Http\Controllers\Reports;

use App\Application\Actions\Reports\StartRosterReportGeneration;
use App\Domain\Enums\ReportGenerationStatus;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\StartRosterReportGenerationRequest;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class RosterReportGenerationController extends Controller
{
    /**
     * Kicks off the report — returns almost immediately (this only
     * creates the tracking row; the real build+render happens after
     * this response is sent, see StartRosterReportGeneration) with a 202
     * Accepted and the generation's id for the frontend to poll.
     */
    public function store(
        StartRosterReportGenerationRequest $request,
        EventModel $event,
        StartRosterReportGeneration $startRosterReportGeneration,
    ) {
        $user = $request->user();

        // Same rule as EventRosterReportController: an SC Admin never
        // gets to pass a different department_id through the query string.
        $departmentId = $user->role === Role::ScAdmin
            ? $user->sc_admin_department_id
            : ($request->integer('department_id') ?: null);

        $generation = $startRosterReportGeneration(
            $event,
            $user,
            $request->string('format')->value() ?: 'xlsx',
            $departmentId,
            $request->string('major')->value() ?: null,
            $request->string('year_level')->value() ?: null,
            $request->string('section')->value() ?: null,
        );

        return response()->json($this->present($generation), 202);
    }

    public function show(ReportGeneration $reportGeneration)
    {
        Gate::authorize('view', $reportGeneration);

        return response()->json($this->present($reportGeneration));
    }

    /**
     * Disks a completed report is allowed to be served from. file_disk is
     * never actually set by application code today (it's just the
     * report_generations migration's column default of 'local'), so this
     * is defense-in-depth rather than a fix for a live bug — but
     * Storage::disk() will happily resolve *any* configured disk name
     * handed to it, so if file_disk ever became settable from a request
     * or a future feature, this stops that from turning into an
     * arbitrary-disk read. Keep in sync with config/filesystems.php if a
     * report is ever legitimately written somewhere other than 'local'.
     */
    private const ALLOWED_DOWNLOAD_DISKS = ['local'];

    public function download(ReportGeneration $reportGeneration)
    {
        Gate::authorize('view', $reportGeneration);

        abort_unless(
            $reportGeneration->status === ReportGenerationStatus::Completed && $reportGeneration->file_path,
            409,
            'This report is not ready to download yet.',
        );

        abort_unless(
            in_array($reportGeneration->file_disk, self::ALLOWED_DOWNLOAD_DISKS, true),
            500,
            'Report is stored on an unexpected disk.',
        );

        // Belt-and-braces against path traversal even though file_path is
        // always server-generated via Str::slug() today (see
        // ProcessRosterReportGeneration/ProcessMasterReportGeneration) —
        // never anything read straight off a request.
        abort_if(
            str_contains($reportGeneration->file_path, '..'),
            500,
            'Report has an unexpected file path.',
        );

        return Storage::disk($reportGeneration->file_disk)
            ->download($reportGeneration->file_path, $reportGeneration->file_name);
    }

    private function present(ReportGeneration $generation): array
    {
        return [
            'id' => $generation->id,
            'status' => $generation->status->value,
            'format' => $generation->format,
            'total_steps' => $generation->total_steps,
            'processed_steps' => min($generation->processed_steps, $generation->total_steps),
            'percentage' => $generation->progressPercent(),
            'error_message' => $generation->error_message,
            'download_url' => $generation->status === ReportGenerationStatus::Completed
                ? route('report-generations.download', $generation, absolute: false)
                : null,
        ];
    }
}
