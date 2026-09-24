<?php

namespace App\Http\Controllers\Reports;

use App\Application\Actions\Reports\StartMasterReportGeneration;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\StartMasterReportGenerationRequest;

class MasterReportGenerationController extends Controller
{
    /**
     * POST /reports/master/report-generations — the "generate report"
     * action that lives on the Reports screen itself, not inside a
     * single event: combines every event_id given into one file, in
     * the order they were given. Returns a 202 with the tracking row's
     * id; the frontend polls/downloads through the same
     * report-generations.show/download routes the per-event flow
     * already uses (RosterReportGenerationController), since a
     * ReportGeneration row looks and behaves the same either way.
     */
    public function store(StartMasterReportGenerationRequest $request, StartMasterReportGeneration $startMasterReportGeneration)
    {
        $user = $request->user();

        // Same rule as every other roster-report endpoint: an SC Admin
        // never gets to pass a different department_id through the
        // request body.
        $departmentId = $user->role === Role::ScAdmin
            ? $user->sc_admin_department_id
            : ($request->integer('department_id') ?: null);

        $generation = $startMasterReportGeneration(
            $request->input('event_ids'),
            $user,
            $request->string('format')->value() ?: 'xlsx',
            $departmentId,
            $request->string('major')->value() ?: null,
            $request->string('year_level')->value() ?: null,
            $request->string('section')->value() ?: null,
        );

        return response()->json([
            'id' => $generation->id,
            'status' => $generation->status->value,
            'format' => $generation->format,
            'total_steps' => $generation->total_steps,
            'processed_steps' => min($generation->processed_steps, $generation->total_steps),
            'percentage' => $generation->progressPercent(),
            'error_message' => $generation->error_message,
            'download_url' => null,
        ], 202);
    }
}
