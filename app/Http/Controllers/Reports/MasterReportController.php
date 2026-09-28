<?php

namespace App\Http\Controllers\Reports;

use App\Application\Actions\Reports\BuildMasterReport;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ShowMasterReportRequest;

class MasterReportController extends Controller
{
    /**
     * GET /reports/master — every event in the current active academic
     * year + semester, each with its total Present/Missed/Late/Excluded,
     * for the "Reports" row on the Settings screen.
     */
    public function show(ShowMasterReportRequest $request, BuildMasterReport $buildMasterReport)
    {
        $user = $request->user();

        // Same "SC Admin never sees past their own department" rule as
        // EventRosterReportController and RosterReportGenerationController.
        $departmentId = $user->role === Role::ScAdmin ? $user->sc_admin_department_id : null;

        $semesterId = $request->validated('semester_id');

        return response()->json($buildMasterReport($departmentId, $semesterId !== null ? (int) $semesterId : null));
    }
}
