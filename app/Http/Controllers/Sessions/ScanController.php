<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\ScanAttendance;
use App\Domain\Enums\ScanOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ScanAttendanceRequest;
use App\Models\AttendanceSession;

class ScanController extends Controller
{
    public function store(ScanAttendanceRequest $request, AttendanceSession $session, ScanAttendance $scanAttendance)
    {
        $record = $scanAttendance($session, $request->string('token')->toString(), $request->user()->id);

        // ScanAttendance is a pure idempotent action (create on first scan,
        // untouched no-op after that) and its own test suite asserts it
        // returns a plain AttendanceRecord — so rather than changing its
        // contract, the "what actually happened" signal for the scanning
        // UI is read off Eloquent's own dirty tracking on the instance it
        // handed back, before we reload it.
        $outcome = $record->wasRecentlyCreated ? ScanOutcome::Recorded : ScanOutcome::Duplicate;

        // student.department eager-loaded so the scan screen can show
        // department + section alongside the name/photo for the officer's
        // face-to-QR cross-check, without a second round trip.
        return response()->json([
            ...$record->fresh(['student.department'])->toArray(),
            'outcome' => $outcome,
        ]);
    }
}
