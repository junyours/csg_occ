<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;

final class BuildMyEventAttendance
{
    private const WINDOW_ORDER = ['morning' => 1, 'afternoon' => 2, 'evening' => 3];

    private const CHECK_ORDER = ['time_in' => 1, 'time_out' => 2];

    /**
     * One student's own attendance across every session of an event —
     * the read-only, single-caller counterpart to BuildEventRosterReport.
     * Same Present/Late/Absent/Excluded reasoning, just scoped to one
     * student instead of the whole roster:
     *
     *  - A session the caller hasn't been checked against yet (no
     *    AttendanceRecord row) reports attendance_status = null. That
     *    covers both "session hasn't started" and "session is ongoing
     *    and I haven't scanned yet" — EndSession is what turns a missing
     *    record into a real Absent, so null before that point just
     *    means "no verdict yet," never a guess.
     *  - Excluded students never get a real record for that session at
     *    all (see Exclusion::excludedStudentIdsForSession / EndSession's
     *    markMissingRecords skip), so exclusion is checked first and
     *    reported directly as 'excluded' rather than sitting as
     *    perpetually-pending.
     *
     * @return array{event_id:int, event_name:string, event_status:string, days:array<array{
     *     id:int, date:string, day_number:int, sessions:array<array{
     *         id:int, window_type:string, check_type:string, start_time:string, end_time:string,
     *         session_status:string, attendance_status:?string, scanned_at:?string, scanned_by_name:?string,
     *     }>,
     * }>}
     */
    public function __invoke(EventModel $event, Student $student): array
    {
        $event->loadMissing('days.sessions');

        $sessions = $event->days->flatMap->sessions;

        $records = AttendanceRecord::whereIn('session_id', $sessions->pluck('id'))
            ->where('student_id', $student->id)
            ->with('scannedBy:id,first_name,last_name')
            ->get()
            ->keyBy('session_id');

        $excludedSessionIds = $sessions
            ->filter(fn (AttendanceSession $session) => in_array(
                $student->id,
                Exclusion::excludedStudentIdsForSession($session),
                true,
            ))
            ->pluck('id')
            ->flip();

        $days = $event->days
            ->sortBy('day_number')
            ->values()
            ->map(fn ($day) => [
                'id' => $day->id,
                'date' => $day->date->format('Y-m-d'),
                'day_number' => $day->day_number,
                'sessions' => $day->sessions
                    ->sortBy(fn (AttendanceSession $session) => sprintf(
                        '%02d-%d',
                        self::WINDOW_ORDER[$session->window_type->value] ?? 99,
                        self::CHECK_ORDER[$session->check_type->value] ?? 99,
                    ))
                    ->values()
                    ->map(function (AttendanceSession $session) use ($records, $excludedSessionIds) {
                        $record = $records->get($session->id);
                        $isExcluded = $excludedSessionIds->has($session->id);

                        return [
                            'id' => $session->id,
                            'window_type' => $session->window_type->value,
                            'check_type' => $session->check_type->value,
                            'start_time' => $session->start_time,
                            'end_time' => $session->end_time,
                            'session_status' => $session->status->value,
                            // A real record wins over the live exclusion
                            // flag (student-exclusion-feature-plan.md §2
                            // rule 4's "mid-window guard"): a student who
                            // genuinely scanned before CSG excluded them
                            // keeps that Present/Late outcome, and an
                            // already-ended session reads from the
                            // permanent row EndSession froze in — which
                            // is itself `excluded` where it should be.
                            // Only a student with no record at all falls
                            // back to the live flag, which is what turns
                            // a still-open session from "pending" into
                            // "Excluded".
                            'attendance_status' => $record?->status->value
                                ?? ($isExcluded ? AttendanceStatus::Excluded->value : null),
                            'scanned_at' => $record?->scanned_at?->toIso8601String(),
                            // "Who scanned me" — no scan exists for an
                            // Absent/Excluded/pending row, so this stays
                            // null rather than guessing.
                            'scanned_by_name' => $record?->scannedBy
                                ? trim("{$record->scannedBy->first_name} {$record->scannedBy->last_name}")
                                : null,
                        ];
                    })
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'event_id' => $event->id,
            'event_name' => $event->name,
            // The event's own lifecycle, separate from any session's
            // status — lets the student UI say "the event is still
            // ongoing" even when every session so far has ended (see
            // App\Domain\Enums\EventStatus).
            'event_status' => $event->status->value,
            'days' => $days,
        ];
    }
}
