<?php

namespace App\Http\Controllers\ServerTime;

use App\Http\Controllers\Controller;
use Carbon\Carbon;

/**
 * A single "what time is it, really" endpoint.
 *
 * The scheduling rules this app enforces — a session's Start button can't
 * be used before its scheduled start time, a scan can't be recorded before
 * a session's window opens — are only real security if they're checked
 * against a clock the caller doesn't control. The backend already does
 * that (StartSession, ScanAttendance both compare against Carbon::now(),
 * this application server's system clock). This endpoint lets the frontend
 * do the same *before* it ever calls those actions: instead of disabling
 * the Start button using the device's own Date.now() (trivially spoofed by
 * changing the device's clock), the client fetches this once, computes the
 * (small, effectively constant) offset between the server's clock and its
 * own, and uses server_time + elapsed_local_time from then on. A spoofed
 * device clock changes what the client *displays* a countdown as, at
 * worst, but can never get the Start/Scan request to actually succeed
 * early — the server-side check in StartSession/ScanAttendance is the
 * real gate either way.
 */
class ServerTimeController extends Controller
{
    public function show()
    {
        return response()->json([
            'now' => Carbon::now()->toIso8601String(),
        ]);
    }
}
