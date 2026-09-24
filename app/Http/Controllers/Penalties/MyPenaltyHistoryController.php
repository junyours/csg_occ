<?php

namespace App\Http\Controllers\Penalties;

use App\Application\Actions\Penalties\BuildMyPenaltyHistory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MyPenaltyHistoryController extends Controller
{
    /**
     * Always the authenticated caller's own penalty ledger — same
     * reasoning as MyAttendanceHistoryController: nothing in the route or
     * request body can be spoofed to pull someone else's records, so
     * being logged in (and past the password-change gate) is the only
     * gate this needs.
     */
    public function show(Request $request, BuildMyPenaltyHistory $buildMyPenaltyHistory)
    {
        return response()->json($buildMyPenaltyHistory($request->user()));
    }
}
