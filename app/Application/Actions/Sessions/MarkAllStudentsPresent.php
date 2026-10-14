<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\SessionNotAcceptingScansException;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MarkAllStudentsPresent
{
    /**
     * Bulk "Mark all students as Present" for one *ongoing* session.
     *
     * Only plain `student` accounts are touched — active ones, in a
     * department the event covers, and not excluded for this session:
     *
     *  - no record yet           -> a Present record is written (scanned_by
     *                               is the admin who pressed the button).
     *  - already Present         -> left exactly as is (never overridden,
     *                               keeps its real scan time and scanner).
     *  - Late                    -> promoted to Present. Its penalty is only
     *                               ever written by EndSession, from the
     *                               final status, so no Late penalty will
     *                               be charged for it.
     *  - excluded                -> left exactly as is, with or without a
     *                               record (EndSession writes their
     *                               permanent `excluded` row).
     *
     * Officers / SC Admins / CSG Admins are deliberately skipped: they are
     * auto-Present when the session ends (EndSession::markMissingRecords),
     * so there is nothing to mark here.
     *
     * @return array{session_id: int, marked_present: int, late_converted: int, already_present: int}
     *
     * @throws SessionNotAcceptingScansException  when the session isn't Ongoing
     */
    public function __invoke(AttendanceSession $session, Student $markedBy): array
    {
        return DB::transaction(function () use ($session, $markedBy) {
            // Same lock EndSession takes, so "mark all" and "end session"
            // can never interleave (a session can't end halfway through).
            $locked = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== SessionStatus::Ongoing) {
                throw new SessionNotAcceptingScansException(
                    'Students can only be marked present while the session is ongoing.',
                );
            }

            $event = $locked->eventDay->event;
            $excludedIds = Exclusion::excludedStudentIdsForSession($locked);

            $eligible = Student::query()
                ->where('role', Role::Student->value)
                ->activeAccounts()
                ->whereIn('department_id', $event->includedDepartmentIds())
                ->when($excludedIds !== [], fn ($query) => $query->whereNotIn('id', $excludedIds))
                ->get(['id', 'department_id', 'major', 'section', 'year_level']);

            $existing = AttendanceRecord::where('session_id', $locked->id)
                ->whereIn('student_id', $eligible->pluck('id'))
                ->pluck('status', 'student_id');

            // Plain now() — see ScanAttendance for why (stored as true UTC).
            $now = Carbon::now();

            $lateIds = [];
            $alreadyPresent = 0;
            $toCreate = [];

            foreach ($eligible as $student) {
                $status = $existing[$student->id] ?? null;
                // pluck() may or may not apply the enum cast; normalize.
                $status = $status instanceof AttendanceStatus ? $status->value : $status;

                if ($status === null) {
                    $toCreate[] = $student;
                } elseif ($status === AttendanceStatus::Late->value) {
                    $lateIds[] = $student->id;
                } elseif ($status === AttendanceStatus::Present->value) {
                    $alreadyPresent++;
                }
                // Anything else (e.g. a stray Missed/Excluded row) stays as is.
            }

            $lateConverted = 0;
            foreach (array_chunk($lateIds, 500) as $chunk) {
                $lateConverted += AttendanceRecord::where('session_id', $locked->id)
                    ->whereIn('student_id', $chunk)
                    ->where('status', AttendanceStatus::Late->value)
                    ->update(['status' => AttendanceStatus::Present->value, 'updated_at' => $now]);
            }

            $created = 0;
            foreach (array_chunk($toCreate, 500) as $chunk) {
                $rows = array_map(fn (Student $student) => [
                    'session_id' => $locked->id,
                    'student_id' => $student->id,
                    'scanned_at' => $now,
                    'status' => AttendanceStatus::Present->value,
                    'scanned_by' => $markedBy->id,
                    ...AttendanceRecord::snapshotFor($student, $event->semester_id),
                    'auto_present' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk);

                // insertOrIgnore: an officer's scan isn't serialized by the
                // session lock, so a student scanned between the read above
                // and this write must win (unique session+student) rather
                // than abort the whole bulk action.
                $created += AttendanceRecord::insertOrIgnore($rows);
            }

            return [
                'session_id' => $locked->id,
                'marked_present' => $created,
                'late_converted' => $lateConverted,
                'already_present' => $alreadyPresent,
            ];
        });
    }
}
