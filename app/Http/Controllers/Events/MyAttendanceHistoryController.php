<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\BuildMyAttendanceHistory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MyAttendanceHistoryController extends Controller
{
    /**
     * Always the authenticated caller's own history — same reasoning as
     * EventMyAttendanceController: nothing in the route or request body
     * can be spoofed to pull someone else's records, so being logged in
     * is the only gate this needs.
     */
    public function show(Request $request, BuildMyAttendanceHistory $buildMyAttendanceHistory)
    {
        return response()->json([
            'entries' => $buildMyAttendanceHistory($request->user()),
        ]);
    }
}
