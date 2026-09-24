<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\BuildMyEventAttendance;
use App\Http\Controllers\Controller;
use App\Models\EventModel;
use Illuminate\Http\Request;

class EventMyAttendanceController extends Controller
{
    /**
     * Always the authenticated caller's own attendance — there's no
     * student_id in the route or request body to spoof, so unlike
     * EventRosterReportController (which needs the viewRosterReport
     * gate before it'll show other people's records) this endpoint
     * needs nothing beyond being logged in: it can never reveal
     * anyone else's data. EventModelPolicy::viewAny (open to every
     * role) already covers "can this account see events at all."
     */
    public function show(Request $request, EventModel $event, BuildMyEventAttendance $buildMyEventAttendance)
    {
        return response()->json($buildMyEventAttendance($event, $request->user()));
    }
}
