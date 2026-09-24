<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\BuildSessionReport;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ShowSessionReportRequest;
use App\Models\AttendanceSession;

class SessionReportController extends Controller
{
    /**
     * Spec §10: "scoped by role — CSG sees all, SC sees own dept" (the
     * same rule as GET /students, spec §10) applies here too — an SC
     * Admin's report is forced to their own department rather than
     * every department at once.
     */
    public function show(ShowSessionReportRequest $request, AttendanceSession $session, BuildSessionReport $buildSessionReport)
    {
        $user = $request->user();
        $departmentId = $user->role === Role::ScAdmin ? $user->sc_admin_department_id : null;

        return response()->json($buildSessionReport($session, $departmentId));
    }
}
