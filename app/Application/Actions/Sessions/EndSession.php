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
     * student with no record for this session as Missed for the single
     * check this session represents. A window with both a time-in and a
     * time-out session ends each independently — ending the time-in
     * session never touches the time-out session's records, and vice
     * versa — since each is its own AttendanceSession row now (see
     * App\Domain\Enums\CheckType).
     *
     * Penalty amounts (configured per session for Late and Missed) are
     * applied here too, for every Late/Missed outcome that exists once
     * the session closes. Late is *decided* the instant a student scans
     * (TimeWindow::classify in ScanAttendance), but the penalty ledger
     * write happens here so every money-affecting write for a session
     * lands in one atomic transaction instead of being split between
     * the scan endpoint and this action.
     *
     * Excluded students with no existing record get a permanent
     * `excluded` AttendanceRecord written here (see markMissingRecords)
     * instead of an Missed one, and no penalty is charged for it. This
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
     * genuinely happened, it only ever prevents new Missed/penalty
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
     * Every active attendee with no record gets one written: an active
     * `student` -> Missed; an active CSG/SC Admin/Officer -> Present at the
     * session start (auto_present, no penalty); anyone excluded -> a
     * permanent `excluded` row (outranks auto-Present). Inactive accounts get
     * nothing; System Admin is never an attendee. Rows carry the enrollment
     * snapshot and the *event's* semester.
     *
     * @return int  Missed rows created.
     */
    private function markMissingRecords(AttendanceSession $session, array $excludedStudentIds, Carbon $now): int
    {
        $studentIdsWithRecord = AttendanceRecord::where('session_id', $session->id)->pluck('student_id');

        $event = $session->eventDay->event;
        $includedDepartmentIds = $event->includedDepartmentIds();
        $semesterId = $event->semester_id;

        // ->utc(): raw insert stores wall-clock digits verbatim; the window
        // start is an Asia/Manila instant.
        $autoPresentAt = $session->window()->start()->copy()->utc();

        $absentCreated = 0;

        $accounts = Student::attendees()
            ->activeAccounts()
            ->whereIn('department_id', $includedDepartmentIds)
            ->whereNotIn('id', $studentIdsWithRecord)
            ->get(['id', 'role', 'department_id', 'major', 'section', 'year_level']);

        foreach ($accounts->chunk(500) as $chunk) {
            $rows = $chunk->map(function (Student $account) use ($session, $excludedStudentIds, $now, $semesterId, $autoPresentAt, &$absentCreated) {
                if (in_array($account->id, $excludedStudentIds, true)) {
                    $status = AttendanceStatus::Excluded;
                } elseif ($account->role !== Role::Student) {
                    $status = AttendanceStatus::Present;
                } else {
                    $status = AttendanceStatus::Missed;
                    $absentCreated++;
                }

                $autoPresent = $status === AttendanceStatus::Present;

                return [
                    'session_id' => $session->id,
                    'student_id' => $account->id,
                    'scanned_at' => $autoPresent ? $autoPresentAt : null,
                    'status' => $status->value,
                    'scanned_by' => null,
                    'department_id' => $account->department_id,
                    'major' => $account->major,
                    'section' => $account->section,
                    'year_level' => $account->year_level,
                    'semester_id' => $semesterId,
                    'auto_present' => $autoPresent,
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
     * penalizeIfNeeded() below only ever charges Late/Missed, so an
     * excluded row (and a Present one) contributes 0 here regardless. A
     * student who reaches this method with a real Present/Late/Missed
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
            AttendanceStatus::Missed => (float) $session->penalty_absent_amount,
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
