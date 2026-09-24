<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\SessionNotAcceptingScansException;
use App\Domain\Exceptions\StaleQrCodeException;
use App\Domain\Exceptions\StudentDepartmentNotIncludedException;
use App\Domain\Exceptions\StudentExcludedException;
use App\Domain\Exceptions\StudentNotEligibleForAttendanceException;
use App\Domain\ValueObjects\QrPayload;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;

final class ScanAttendance
{
    /**
     * Scan a student's QR token against a session.
     *
     * Each session now represents exactly one check (time-in or time-out
     * — see App\Domain\Enums\CheckType), so this action doesn't branch on
     * scan order at all: the first scan for this session+student creates
     * the record, classified Present/Late against the session's own
     * window. Any scan after that is a duplicate and is a no-op — it
     * returns the existing record untouched rather than throwing, so a
     * nervous re-scan at the gate can never double-write or
     * double-penalize. A student who needs both a time-in and a time-out
     * check on the same window scans twice, once per session row.
     *
     * The duplicate check runs *before* the exclusion check
     * (student-exclusion-feature-plan.md §2 rule 4's "mid-window guard"):
     * a student who already has a real record for this session — they
     * scanned before CSG excluded them mid-window — must keep getting
     * their normal Present/Late result back on a re-scan, not suddenly
     * be told they're excluded. A brand-new scan attempt from a student
     * with no record yet is still blocked below if they're excluded.
     *
     * @throws \App\Domain\Exceptions\InvalidQrPayloadException
     * @throws StaleQrCodeException
     * @throws SessionNotAcceptingScansException
     */
    public function __invoke(AttendanceSession $session, string $qrToken, ?int $scannedByStaffId = null): AttendanceRecord
    {
        $payload = QrPayload::decrypt($qrToken);

        $student = Student::where('student_number', $payload->studentNumber)->first();

        if (! $student || $payload->qrVersion !== $student->qr_version) {
            throw new StaleQrCodeException;
        }

        // Checked here, not just skipped by EndSession's sweep, so a
        // staff/officiating scan or a wrong-department scan is rejected
        // at the point of scanning rather than silently accepted and
        // only quietly ignored later. Split into two checks (rather than
        // one call to Student::isAttendanceEligibleForEvent) so the
        // officer sees the actual reason instead of one generic message.
        if ($student->role !== Role::Student) {
            throw new StudentNotEligibleForAttendanceException;
        }

        $event = $session->eventDay->event;

        if (! $event->includesDepartment($student->department_id)) {
            throw new StudentDepartmentNotIncludedException;
        }

        if ($session->status !== SessionStatus::Ongoing) {
            throw new SessionNotAcceptingScansException;
        }

        $existing = AttendanceRecord::where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->first();

        if ($existing) {
            // Duplicate scan: return as-is, no write, no exclusion check —
            // this record is already real and stands regardless of any
            // exclusion added afterward (mid-window guard).
            return $existing;
        }

        if (in_array($student->id, Exclusion::excludedStudentIdsForSession($session), true)) {
            throw new StudentExcludedException;
        }

        // Plain now() — this is the same real instant Carbon::now('Asia/Manila')
        // would give, but stored/labeled as UTC so it round-trips correctly
        // through the datetime cast (Eloquent persists whatever timezone a
        // Carbon instance is holding, verbatim, without converting it — so
        // tagging "now" as Asia/Manila here would silently store Manila
        // wall-clock digits as if they were UTC, corrupting every read-back
        // and JSON response by 8 hours). Session boundaries are still parsed
        // as Asia/Manila in AttendanceSession::combineWithDate(), which is
        // correct there because it's converting an admin-entered wall-clock
        // time into an absolute instant, not persisting a "now" value.
        $now = Carbon::now();

        try {
            return AttendanceRecord::create([
                'session_id' => $session->id,
                'student_id' => $student->id,
                'scanned_at' => $now,
                'status' => $session->window()->classify($now),
                'scanned_by' => $scannedByStaffId,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Two officers scanning the same badge within milliseconds of
            // each other can both pass the $existing check above as null
            // before either INSERT commits — with 5-10 officers scanning
            // concurrently this isn't a theoretical edge case. The unique
            // index on (session_id, student_id) guarantees only one row
            // ever exists, but the *losing* request would otherwise
            // surface a raw DB exception to that officer's scanner instead
            // of the same graceful "already scanned" no-op the sequential
            // duplicate path above returns. Re-fetch and return the row
            // the other request just won, so both officers see a normal
            // (if one of them slightly delayed) success/duplicate result.
            return AttendanceRecord::where('session_id', $session->id)
                ->where('student_id', $student->id)
                ->firstOrFail();
        }
    }
}
