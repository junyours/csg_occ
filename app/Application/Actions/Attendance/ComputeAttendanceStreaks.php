<?php

namespace App\Application\Actions\Attendance;

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class ComputeAttendanceStreaks
{
    private const WINDOW_ORDER = ['morning' => 1, 'afternoon' => 2, 'evening' => 3];

    private const CHECK_ORDER = ['time_in' => 1, 'time_out' => 2];

    /**
     * Every student's attendance streak, computed across EVERY event in
     * true chronological order rather than reset per event: a streak
     * built up in one event keeps going straight into the next one's
     * sessions once that event ends, exactly like it kept going from one
     * session to the next inside a single event before. There's no
     * per-event boundary in this reduction at all — sessions from every
     * event are walked as a single timeline, ordered by when they
     * actually happened (event_day.date + session start_time, with
     * window/check order as a tiebreak for two sessions starting at the
     * exact same instant), not by which event they belong to or when
     * that event was created.
     *
     * The reduction rule per session, in that chronological order, is
     * unchanged from the old single-event version:
     *
     *  - Present: the running streak goes up by one, and becomes the new
     *    "longest" if it's now the best run seen so far.
     *  - Late or Absent: the running streak resets to 0. These are the
     *    only two outcomes that ever break a streak.
     *  - Excluded, or not yet resolved (the session hasn't ended and the
     *    student hasn't scanned): the running streak is left completely
     *    untouched — neither incremented nor reset. An excluded student
     *    was never expected to attend that session, and a pending one
     *    simply hasn't happened yet, so neither can count for or against
     *    a streak that's otherwise intact.
     *
     * Also tracks, per student, the scanned_at of their most recent real
     * scan (Present or Late — Absent never has one) — this is what
     * BuildStreakLeaderboard uses to break a tie between two students on
     * the same current streak: whoever's last scan landed earlier ranks
     * first. Because the sessions are walked in ascending chronological
     * order, this value is simply overwritten every time a newer scan is
     * seen, so by the end it's always the single most recent one — an
     * old scan from three events ago can never win out over a more
     * recent one, and a Late scan (which itself resets the streak to 0)
     * still updates it, since it's still a genuine "last time this
     * student scanned".
     *
     * A student is only ever checked against sessions belonging to
     * events that include their department (EventModel::includesDepartment)
     * — a session from an event that never concerned them would just sit
     * as perpetually unresolved and get skipped anyway, so this filters
     * it out up front instead of doing the same no-op work for nothing.
     *
     * @return Collection<int, array{current: int, longest: int, latest_scan_at: ?Carbon}>
     *         keyed by student_id. Every role=student student is
     *         represented, even ones with a zero streak.
     */
    public function __invoke(?int $onlyStudentId = null): Collection
    {
        $students = Student::where('role', Role::Student)
            ->when($onlyStudentId, fn ($q) => $q->where('id', $onlyStudentId))
            ->get(['id', 'department_id']);

        $zero = ['current' => 0, 'longest' => 0, 'latest_scan_at' => null];

        if ($students->isEmpty()) {
            return collect();
        }

        $sessions = AttendanceSession::with('eventDay.event.departments')
            ->get()
            ->sortBy(function (AttendanceSession $session) {
                $day = $session->eventDay;

                // A sortable string key rather than a real timestamp
                // comparison — cheap, stable, and matches the same
                // "date + time + window/check" tiebreak pattern already
                // used by BuildMyEventAttendance / BuildEventRosterReport
                // for ordering sessions, just extended across events by
                // leading with the calendar date instead of day_number.
                return sprintf(
                    '%s %s-%02d-%d',
                    $day->date->format('Y-m-d'),
                    $session->start_time,
                    self::WINDOW_ORDER[$session->window_type->value] ?? 99,
                    self::CHECK_ORDER[$session->check_type->value] ?? 99,
                );
            })
            ->values();

        if ($sessions->isEmpty()) {
            return $students->mapWithKeys(fn (Student $s) => [$s->id => $zero]);
        }

        $studentIds = $students->pluck('id');

        $recordsBySession = AttendanceRecord::whereIn('session_id', $sessions->pluck('id'))
            ->whereIn('student_id', $studentIds)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $records) => $records->keyBy('student_id'));

        // One exclusion lookup per session — same pattern
        // BuildEventRosterReport already uses — rather than one per
        // (session, student) pair.
        $excludedBySession = $sessions->mapWithKeys(
            fn (AttendanceSession $s) => [$s->id => collect(Exclusion::excludedStudentIdsForSession($s))->flip()]
        );

        // includedDepartmentIds() does its own query — cache it once per
        // event rather than recomputing it for every session under that
        // event (and again for every student).
        $includedDeptsByEvent = [];
        $eligible = function (EventModel $event, ?int $departmentId) use (&$includedDeptsByEvent) {
            if (! array_key_exists($event->id, $includedDeptsByEvent)) {
                $includedDeptsByEvent[$event->id] = $event->includedDepartmentIds();
            }

            return $departmentId !== null && in_array($departmentId, $includedDeptsByEvent[$event->id], true);
        };

        return $students->mapWithKeys(function (Student $student) use ($sessions, $recordsBySession, $excludedBySession, $eligible) {
            $running = 0;
            $longest = 0;
            $latestScanAt = null;

            foreach ($sessions as $session) {
                if (! $eligible($session->eventDay->event, $student->department_id)) {
                    continue;
                }

                $record = $recordsBySession->get($session->id, collect())->get($student->id);
                $isExcluded = $excludedBySession->get($session->id, collect())->has($student->id);

                $status = $record?->status
                    ?? ($isExcluded ? AttendanceStatus::Excluded : null);

                if ($status === AttendanceStatus::Present) {
                    $running += 1;
                    $longest = max($longest, $running);

                    if ($record?->scanned_at) {
                        $latestScanAt = $record->scanned_at;
                    }
                } elseif ($status === AttendanceStatus::Late) {
                    $running = 0;

                    if ($record?->scanned_at) {
                        $latestScanAt = $record->scanned_at;
                    }
                } elseif ($status === AttendanceStatus::Absent) {
                    $running = 0;
                }
                // Excluded, or still-pending (null): carries through
                // untouched — no add, no reset, no scan to record.
            }

            return [$student->id => [
                'current' => $running,
                'longest' => $longest,
                'latest_scan_at' => $latestScanAt,
            ]];
        });
    }
}
