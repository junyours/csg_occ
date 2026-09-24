<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\BuildRecentScans;
use App\Domain\Enums\ScanOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ShowRecentScansRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;

class RecentScansController extends Controller
{
    public function index(ShowRecentScansRequest $request, AttendanceSession $session, BuildRecentScans $buildRecentScans)
    {
        $records = $buildRecentScans(
            $session,
            (int) $request->integer('limit', BuildRecentScans::DEFAULT_LIMIT),
        );

        // Shaped exactly like ScanController's single-scan response —
        // same keys, same nested student, same `outcome` field — so the
        // frontend maps both through one function instead of keeping two
        // near-identical parsers in sync.
        //
        // `outcome` is always Recorded here: a Duplicate is by definition
        // not a new entry, it's the officer re-reading a badge that's
        // already in this list, so nothing in the persisted record can
        // (or should) reconstruct it after the fact.
        return response()->json(
            $records->map(fn (AttendanceRecord $record) => [
                ...$record->toArray(),
                'outcome' => ScanOutcome::Recorded,
            ])->all(),
        );
    }
}
