<?php

namespace App\Http\Controllers\Attendance;

use App\Application\Actions\Attendance\BuildAttendanceHistory;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\IndexAttendanceHistoryRequest;

class AttendanceHistoryController extends Controller
{
    /**
     * Spec: "scoped by role — CSG sees all, SC sees own dept" — same
     * rule StudentController::index and PenaltyController::index both
     * apply. An SC Admin's department_id is forced to the one they
     * administer even if a different one was requested, rather than
     * 403ing on a mismatch.
     *
     * An Officer is forced the same way, but on scanned_by rather than
     * department_id: they're only ever shown records they personally
     * scanned, regardless of what department_id/event_id/etc. they pass —
     * this is their "who did I scan" history, not the org-wide ledger.
     */
    public function index(IndexAttendanceHistoryRequest $request, BuildAttendanceHistory $buildAttendanceHistory)
    {
        $user = $request->user();
        $filters = $request->validated();

        if ($user->role === Role::ScAdmin) {
            $filters['department_id'] = $user->sc_admin_department_id;
        }

        if ($user->role === Role::Officer) {
            $filters['scanned_by'] = $user->id;
        }

        return response()->json($buildAttendanceHistory($filters));
    }
}
