<?php

namespace App\Http\Controllers\Attendance;

use App\Application\Actions\Attendance\BuildAttendanceHistoryFilterOptions;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\IndexAttendanceHistoryFilterOptionsRequest;

class AttendanceHistoryFilterOptionsController extends Controller
{
    /**
     * Feeds the autosuggest dropdowns on the ledger's Major/Year
     * level/Section filters. Same SC Admin department-forcing as
     * AttendanceHistoryController::index, so an SC Admin only ever sees
     * their own department's values on offer.
     */
    public function index(
        IndexAttendanceHistoryFilterOptionsRequest $request,
        BuildAttendanceHistoryFilterOptions $buildFilterOptions,
    ) {
        $user = $request->user();
        $filters = [];

        if ($user->role === Role::ScAdmin) {
            $filters['department_id'] = $user->sc_admin_department_id;
        }

        return response()->json($buildFilterOptions($filters));
    }
}
