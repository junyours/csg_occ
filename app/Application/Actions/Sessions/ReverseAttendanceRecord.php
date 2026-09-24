<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\AttendanceRecordNotReversibleException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRecordReversal;
use App\Models\AttendanceSession;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class ReverseAttendanceRecord
{
    /**
     * The reason stored when the officer doesn't type one. Reversal at
     * the gate has to be a one-tap correction — the queue doesn't stop
     * while someone types — but `attendance_record_reversals.reason` is
     * NOT NULL because an audit row with a blank reason is worthless, so
     * the default is spelled out here rather than left to the caller.
     */
    public const DEFAULT_REASON = 'Reversed by the scanning officer — QR presented by someone other than its owner.';

    /**
     * Undo a scan that shouldn't have counted — the case this exists for
     * is a student handing their QR to a friend who isn't at the event.
     * The officer sees the photo on the scan panel, sees it isn't the
     * person in front of them, and reverses it.
     *
     * "Reversed" here means the attendance_records row is *deleted*, not
     * flagged. That's deliberate, and it's the opposite of how penalty
     * reversal works (AttendancePenalty keeps the row and sets
     * is_reversed):
     *
     *  - The spec for this is "back to pending, can be scanned again."
     *    Pending is the *absence* of a record — every reader in the app
     *    (BuildSessionReport, BuildAttendanceHistory, the roster/master
     *    reports, EndSession's absent sweep) already treats "no row" as
     *    pending/not-yet-checked-in. Deleting therefore restores exactly
     *    that state everywhere at once, with no reader changes.
     *  - A flag column would instead need every one of those readers to
     *    learn to skip reversed rows, and would collide with the unique
     *    index on (session_id, student_id) the moment the real owner
     *    scanned — there'd already be a row for that pair.
     *
     * Because the row is gone, `attendance_record_reversals` is the only
     * surviving evidence the wrong scan ever happened, so it stores a
     * full snapshot (status, scanned_at, who scanned it) rather than a
     * foreign key to a row that no longer exists.
     *
     * @throws AttendanceRecordNotReversibleException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException  when the record isn't part of this session
     */
    public function __invoke(
        AttendanceSession $session,
        AttendanceRecord $record,
        Student $reversedBy,
        ?string $reason = null,
    ): AttendanceRecordReversal {
        $reason = trim((string) $reason);
        $reason = $reason !== '' ? $reason : self::DEFAULT_REASON;

        return DB::transaction(function () use ($session, $record, $reversedBy, $reason) {
            // Session first, then the record — the same lock order
            // EndSession takes (it locks the session, then writes
            // records). Taking them in the opposite order here would let
            // a concurrent "end session" and "reverse scan" each hold
            // one lock while waiting on the other's, which is a textbook
            // deadlock; with a consistent order one of them simply waits.
            $lockedSession = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            if ($lockedSession->status !== SessionStatus::Ongoing) {
                throw new AttendanceRecordNotReversibleException(
                    'This session is no longer open, so its scans can no longer be reversed.',
                );
            }

            // Scoped to the session in the query itself rather than
            // trusting the route: /sessions/{session}/records/{record}
            // has two independently-bound models, and nothing else
            // stops an officer from pointing a record id from some
            // other session at a session they *are* allowed to scan.
            // firstOrFail → a plain 404, same as any unknown record.
            //
            // The lock also closes the double-reverse race: two officers
            // tapping reverse on the same row at once would otherwise
            // both read it, both write an audit row, and the second
            // delete would be a silent no-op.
            $locked = AttendanceRecord::whereKey($record->id)
                ->where('session_id', $lockedSession->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->scanned_at === null) {
                // Defensive: the only rows with a null scanned_at are the
                // Absent ones EndSession backfills, which means the
                // session already ended and the status guard above should
                // have caught it. Kept so a future writer of null-scan
                // rows can't quietly make "reverse" mean "delete an
                // absence" — that's a penalty reversal, not this.
                throw new AttendanceRecordNotReversibleException(
                    'Only a scanned record can be reversed.',
                );
            }

            $reversal = AttendanceRecordReversal::create([
                'session_id' => $locked->session_id,
                'student_id' => $locked->student_id,
                'status' => $locked->status,
                'scanned_at' => $locked->scanned_at,
                'scanned_by' => $locked->scanned_by,
                'reversed_by' => $reversedBy->id,
                'reason' => $reason,
            ]);

            $locked->delete();

            return $reversal;
        });
    }
}
