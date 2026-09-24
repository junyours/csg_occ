<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\CheckType;
use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\SessionAlreadyEndedException;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class EndSession
{
    /**
     * End a session (spec §7.2): mark every eligible, non-excluded
     * student with no record for this session as Absent for the single
     * check this session represents. A window with both a time-in and a
     * time-out session ends each independently — ending the time-in
     * session never touches the time-out session's records, and vice
     * versa — since each is its own AttendanceSession row now (see
     * App\Domain\Enums\CheckType).
     *
     * Penalty amounts (configured per session for Late and Absent) are
     * applied here too, for every Late/Absent outcome that exists once
     * the session closes. Late is *decided* the instant a student scans
     * (TimeWindow::classify in ScanAttendance), but the penalty ledger
     * write happens here so every money-affecting write for a session
     * lands in one atomic transaction instead of being split between
     * the scan endpoint and this action.
     *
     * Excluded students with no existing record get a permanent
     * `excluded` AttendanceRecord written here (see markMissingRecords)
     * instead of an Absent one, and no penalty is charged for it. This
     * is written as a real row — not left to be derived live from the
     * exclusions table at report time — specifically so an already-
     * ended session's outcome can never change again: per
     * student-exclusion-feature-plan.md §6a point 3, once a day/window
     * has ended, its "Excluded" reads must survive even if the
     * exclusion behind it is later removed (removal only clears
     * *future* sessions from being covered — see
     * App\Application\Actions\Exclusions\RemoveExclusion and
     * App\Models\Exclusion::excludedStudentIdsForSession, which only
     * ever matches *active* rows and would otherwise silently "forget"
     * this session the moment the exclusion's status flips to removed).
     *
     * A student who has a *real* record already (they scanned before
     * being excluded — §2 rule 4's "mid-window guard") keeps that
     * record and its penalty exactly as if they'd never been excluded:
     * an exclusion never rewrites or un-charges something that already
     * genuinely happened, it only ever prevents new Absent/penalty
     * writes going forward.
     *
     * @return array{session_id: int, present: int, late: int, absent_created: int, penalty_total: float}
     *
     * @throws SessionAlreadyEndedException
     */
    public function __invoke(AttendanceSession $session): array
    {
        return DB::transaction(function () use ($session) {
            // Lock the row so two concurrent "end session" requests can't
            // both pass the status check and double-run this action.
            $locked = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status === SessionStatus::Ended) {
                throw new SessionAlreadyEndedException;
            }

            $excludedStudentIds = $this->excludedStudentIds($locked);
            // See the comment in ScanAttendance::__invoke() — plain now(),
            // not now('Asia/Manila'), so these timestamps store and
            // round-trip as true UTC instants.
            $now = Carbon::now();

            $absentCreated = $this->markMissingRecords($locked, $excludedStudentIds, $now);
            $summary = $this->applyPenalties($locked);

            $locked->update(['status' => SessionStatus::Ended, 'ended_at' => $now]);

            return array_merge($summary, [
                'session_id' => $locked->id,
                'absent_created' => $absentCreated,
            ]);
        });
    }

    /**
     * Every student with zero attendance_records row for this session
     * gets one written here: Absent for the single check this session
     * covers, or — for a student currently excluded from it — a
     * permanent `excluded` row instead (see the class docblock for why
     * this is written as a real row rather than left to be derived live
     * from the exclusions table).
     */
    private function markMissingRecords(AttendanceSession $session, array $excludedStudentIds, Carbon $now): int
    {
        $studentIdsWithRecord = AttendanceRecord::where('session_id', $session->id)->pluck('student_id');

        // Mirrors Student::isAttendanceEligibleForEvent in SQL form (a bulk
        // query can't call the per-row model method): only role=student
        // accounts are ever eligible. System Admin, CSG Admin, SC Admin,
        // and Officer never appear here — they're staff running or
        // staffing the event, not people being checked for attendance.
        //
        // Also scoped to the event's included departments (see
        // EventModel::includedDepartmentIds) — a student whose department
        // was never part of this event must never be swept into Absent
        // (and therefore never penalized) here just because they have no
        // record for a session they were never supposed to attend.
        $includedDepartmentIds = $session->eventDay->event->includedDepartmentIds();

        $missingStudentIds = Student::where('role', Role::Student)
            ->whereIn('department_id', $includedDepartmentIds)
            ->whereNotIn('id', $studentIdsWithRecord)
            ->pluck('id');

        $absentCreated = 0;

        foreach ($missingStudentIds->chunk(500) as $chunk) {
            $rows = $chunk->map(function (int $studentId) use ($session, $excludedStudentIds, $now, &$absentCreated) {
                $isExcluded = in_array($studentId, $excludedStudentIds, true);
                $absentCreated += $isExcluded ? 0 : 1;

                return [
                    'session_id' => $session->id,
                    'student_id' => $studentId,
                    'scanned_at' => null,
                    'status' => ($isExcluded ? AttendanceStatus::Excluded : AttendanceStatus::Absent)->value,
                    'scanned_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            AttendanceRecord::insert($rows);
        }

        return $absentCreated;
    }

    private function excludedStudentIds(AttendanceSession $session): array
    {
        return Exclusion::excludedStudentIdsForSession($session);
    }

    /**
     * Runs over every attendance_records row for this session, including
     * the `excluded` rows markMissingRecords() just wrote — but
     * penalizeIfNeeded() below only ever charges Late/Absent, so an
     * excluded row (and a Present one) contributes 0 here regardless. A
     * student who reaches this method with a real Present/Late/Absent
     * status despite currently being excluded only got there via a
     * genuine pre-exclusion scan (the mid-window guard case) and is
     * penalized exactly as if they'd never been excluded.
     *
     * @return array{present: int, late: int, penalty_total: float}
     */
    private function applyPenalties(AttendanceSession $session): array
    {
        $counts = ['present' => 0, 'late' => 0, 'penalty_total' => 0.0];
        $checkLabel = $session->check_type === CheckType::TimeOut ? 'Time Out' : 'Time In';

        AttendanceRecord::where('session_id', $session->id)
            ->chunkById(500, function ($records) use ($session, $checkLabel, &$counts) {
                foreach ($records as $record) {
                    $counts['present'] += (int) ($record->status === AttendanceStatus::Present);
                    $counts['late'] += (int) ($record->status === AttendanceStatus::Late);

                    $counts['penalty_total'] += $this->penalizeIfNeeded($session, $record, $record->status, $checkLabel);
                }
            });

        return $counts;
    }

    /**
     * Idempotent by (student_id, session_id, reason): if this exact
     * penalty already exists — e.g. this method somehow ran twice for
     * the same record — it's returned, not duplicated, and contributes
     * 0 to this call's running total.
     */
    private function penalizeIfNeeded(AttendanceSession $session, AttendanceRecord $record, AttendanceStatus $status, string $checkLabel): float
    {
        $amount = match ($status) {
            AttendanceStatus::Late => (float) $session->penalty_late_amount,
            AttendanceStatus::Absent => (float) $session->penalty_absent_amount,
            default => 0.0,
        };

        if ($amount <= 0.0) {
            return 0.0;
        }

        $penalty = AttendancePenalty::firstOrCreate(
            [
                'student_id' => $record->student_id,
                'session_id' => $session->id,
                'reason' => sprintf('%s - %s', ucfirst($status->value), $checkLabel),
            ],
            ['amount' => $amount],
        );

        return $penalty->wasRecentlyCreated ? $amount : 0.0;
    }
}
