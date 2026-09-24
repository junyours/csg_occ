<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\ReverseAttendanceRecord;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\ReverseScanRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;

class ReverseScanController extends Controller
{
    public function store(
        ReverseScanRequest $request,
        AttendanceSession $session,
        AttendanceRecord $record,
        ReverseAttendanceRecord $reverseAttendanceRecord,
    ) {
        // Read before the action runs: the record row is deleted by the
        // reversal (see ReverseAttendanceRecord), so afterwards
        // $record->id is the id of something that no longer exists. The
        // scanning UI still needs it to drop the right row out of its
        // "Recent scans" list, so it's captured here first.
        $recordId = $record->id;

        $reversal = $reverseAttendanceRecord(
            $session,
            $record,
            $request->user(),
            $request->validated('reason'),
        );

        return response()->json([
            'record_id' => $recordId,
            'student_id' => $reversal->student_id,
            // A literal, not App\Domain\Enums\AttendanceStatus — "pending"
            // isn't a value that's ever stored in attendance_records.
            // It's the *absence* of a row, which is exactly what the
            // reversal just restored, and it matches the Pending case the
            // frontend's own enum already carries for this state.
            'status' => 'pending',
            'reason' => $reversal->reason,
            'reversed_by' => $reversal->reversed_by,
            'reversed_at' => $reversal->created_at,
        ]);
    }
}
