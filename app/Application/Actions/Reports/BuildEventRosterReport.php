<?php

namespace App\Application\Actions\Reports;

use App\Domain\Enums\AttendanceStatus;
use App\Domain\Enums\Role;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Collection;

final class BuildEventRosterReport
{
    private const WINDOW_ORDER = ['morning' => 1, 'afternoon' => 2, 'evening' => 3];

    private const CHECK_ORDER = ['time_in' => 1, 'time_out' => 2];

    /**
     * The report request: a printable roster grouped by
     * department -> year level -> section (the spec's own example,
     * "BSIT, 1st year, 1st section -> BSIT 1A"), one row per student
     * sorted a-z by last name, one column per session in the event
     * showing that student's Present/Late/Absent/Excluded status, and
     * a running penalty total per student — the same building blocks
     * BuildSessionReport already uses per-session, just assembled
     * across every session in the event and bucketed into groups
     * instead of scoped to one session.
     *
     * @param  int|null  $departmentId  Forced to an SC Admin's own
     *                                  department by the caller;
     *                                  otherwise an optional narrowing
     *                                  filter.
     * @param  string|null  $major  Optional narrowing filter.
     * @param  string|null  $yearLevel  Optional narrowing filter.
     * @param  string|null  $section  Optional narrowing filter.
     * @param  callable|null  $onGroupBuilt  Invoked once per
     *                                       department/year-level/section
     *                                       group as it finishes building
     *                                       — ProcessRosterReportGeneration
     *                                       uses this to advance a real
     *                                       progress counter while a
     *                                       full-school report (the case
     *                                       that used to just hang) is
     *                                       being assembled, group by
     *                                       group, rather than a fake
     *                                       timer. Null everywhere else,
     *                                       including every existing
     *                                       caller/test — a no-op.
     * @return array{event: array, sessions: array, groups: array}
     */
    public function __invoke(
        EventModel $event,
        ?int $departmentId = null,
        ?string $major = null,
        ?string $yearLevel = null,
        ?string $section = null,
        ?callable $onGroupBuilt = null,
    ): array {
        $sessions = AttendanceSession::whereHas('eventDay', fn ($q) => $q->where('event_id', $event->id))
            ->with('eventDay')
            ->get()
            ->sortBy(fn (AttendanceSession $s) => sprintf(
                '%03d-%d-%d',
                $s->eventDay->day_number,
                self::WINDOW_ORDER[$s->window_type->value] ?? 99,
                self::CHECK_ORDER[$s->check_type->value] ?? 99,
            ))
            ->values();

        // Never lists a student whose department isn't part of this
        // event's scope (see EventModel::includedDepartmentIds) — same
        // reasoning as BuildSessionReport.
        $includedDepartmentIds = $event->includedDepartmentIds();

        $roster = Student::where('role', Role::Student)
            ->whereIn('department_id', $includedDepartmentIds)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when(filled($major), fn ($q) => $q->where('major', $major))
            ->when(filled($yearLevel), fn ($q) => $q->where('year_level', $yearLevel))
            ->when(filled($section), fn ($q) => $q->where('section', $section))
            ->with('department')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $sessionIds = $sessions->pluck('id');
        $rosterIds = $roster->pluck('id');

        $recordsBySession = AttendanceRecord::whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $records) => $records->keyBy('student_id'));

        $excludedBySession = $sessions->mapWithKeys(
            fn (AttendanceSession $s) => [$s->id => Exclusion::excludedStudentIdsForSession($s)]
        );

        // Pulled once for both uses below, so the reversed-state view and
        // the money view can never disagree about the same row.
        $penalties = AttendancePenalty::whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->get()
            ->groupBy('student_id');

        $penaltyTotals = $penalties->map(
            fn (Collection $forStudent) => (float) $forStudent->where('is_reversed', false)->sum('amount')
        );

        // A reversed penalty means the charge was forgiven, so the roster
        // must not keep reading "Absent" (or "Late") for that session —
        // it reads "Reversed" instead, matching what the penalty column
        // already says by charging 0 for it.
        //
        // Guarded on there being no *surviving* penalty for the session:
        // AttendancePenalty has no unique (student_id, session_id) key and
        // EndSession's firstOrCreate is keyed on reason too, so a session
        // can in principle hold both a reversed row and a live one. In
        // that case the student still owes money, so "Reversed" would be a
        // lie — only a session with nothing left outstanding flips.
        $reversedSessionIdsByStudent = $penalties->map(function (Collection $forStudent) {
            $activeSessionIds = $forStudent->where('is_reversed', false)->pluck('session_id')->flip();

            return $forStudent->where('is_reversed', true)
                ->pluck('session_id')
                ->reject(fn (int $sessionId) => $activeSessionIds->has($sessionId))
                ->flip();
        });

        $sessionMeta = $sessions->map(fn (AttendanceSession $s) => [
            'id' => $s->id,
            'day_number' => $s->eventDay->day_number,
            'window_type' => $s->window_type->value,
            'check_type' => $s->check_type->value,
            'label' => 'Day '.$s->eventDay->day_number.' — '.ucfirst($s->window_type->value)
                .' — '.($s->check_type->value === 'time_in' ? 'Time In' : 'Time Out'),
        ])->values()->all();

        $groups = $roster
            // major is part of the key — otherwise e.g. BSBA-FM-1A and
            // BSBA-MM-1A would collide into one group (see
            // BuildMasterRosterReport, which shares this exact shape).
            ->groupBy(fn (Student $s) => sprintf(
                '%s|%s|%s|%s',
                $s->department?->code ?? '—',
                $s->major ?? '',
                $s->year_level ?? '—',
                $s->section ?? '—',
            ))
            ->map(function (Collection $students, string $key) use ($sessions, $recordsBySession, $excludedBySession, $penaltyTotals, $reversedSessionIdsByStudent, $onGroupBuilt) {
                $group = $this->buildGroup(
                    $key, $students, $sessions, $recordsBySession, $excludedBySession, $penaltyTotals, $reversedSessionIdsByStudent,
                );

                if ($onGroupBuilt !== null) {
                    $onGroupBuilt();
                }

                return $group;
            })
            ->sortBy(fn (array $group) => sprintf(
                '%s-%s-%03d-%s', $group['department_code'], $group['major'] ?: '', (int) $group['year_level'], $group['section'],
            ))
            ->values()
            ->all();

        return [
            'event' => ['id' => $event->id, 'name' => $event->name],
            'sessions' => $sessionMeta,
            'groups' => $groups,
        ];
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, AttendanceSession>  $sessions
     * @param  Collection<int, Collection>  $recordsBySession
     * @param  Collection<int, array<int>>  $excludedBySession
     * @param  Collection<int, float>  $penaltyTotals
     * @param  Collection<int, Collection<int, int>>  $reversedSessionIdsByStudent
     */
    private function buildGroup(
        string $key,
        Collection $students,
        Collection $sessions,
        Collection $recordsBySession,
        Collection $excludedBySession,
        Collection $penaltyTotals,
        Collection $reversedSessionIdsByStudent,
    ): array {
        [$deptCode, $major, $yearLevel, $section] = explode('|', $key);

        $studentRows = $students
            ->map(fn (Student $student) => [
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'last_name' => $student->last_name,
                'first_name' => $student->first_name,
                'middle_name' => $student->middle_name,
                'sessions' => $this->sessionStatusesFor(
                    $student, $sessions, $recordsBySession, $excludedBySession,
                    $reversedSessionIdsByStudent->get($student->id, collect()),
                ),
                'penalty_total' => $penaltyTotals->get($student->id, 0.0),
            ])
            ->values()
            ->all();

        return [
            'department_code' => $deptCode,
            'major' => $major !== '' ? $major : null,
            'year_level' => $yearLevel,
            'section' => $section,
            'students' => $studentRows,
            'group_penalty_total' => array_sum(array_column($studentRows, 'penalty_total')),
        ];
    }

    /**
     * @param  Collection<int, AttendanceSession>  $sessions
     * @param  Collection<int, Collection>  $recordsBySession
     * @param  Collection<int, array<int>>  $excludedBySession
     * @param  Collection<int, int>  $reversedSessionIds  Session ids (as keys)
     *                                                    whose Late/Absent
     *                                                    penalty for this
     *                                                    student was reversed
     *                                                    and left nothing owed.
     * @return array<int, ?string>
     */
    private function sessionStatusesFor(
        Student $student,
        Collection $sessions,
        Collection $recordsBySession,
        Collection $excludedBySession,
        Collection $reversedSessionIds,
    ): array {
        $statuses = [];

        foreach ($sessions as $session) {
            $isExcluded = in_array($student->id, $excludedBySession->get($session->id, []), true);
            $record = $recordsBySession->get($session->id, collect())->get($student->id);

            $statuses[$session->id] = match (true) {
                // A stored `excluded` record outranks everything,
                // reversal included. EndSession froze that row in when
                // the session closed (student-exclusion-feature-plan.md
                // §6a point 3) and the student was never charged for it,
                // so a reversed penalty sitting on the same session says
                // nothing about this cell — relabelling it "Reversed"
                // would claim the student was charged and forgiven, when
                // the plan's record is that they were never expected to
                // attend at all.
                $record !== null && $record->status === AttendanceStatus::Excluded => 'excluded',
                // Otherwise a real record still wins over the live
                // exclusion flag below: it can only exist here because
                // the student genuinely scanned before being excluded
                // (§2 rule 4's "mid-window guard") — that outcome is
                // never rewritten to "Excluded" after the fact.
                //
                // Reversed outranks the stored Absent/Late: the record is
                // still historically accurate, but the roster is a
                // penalty-facing document and the charge was undone.
                $record !== null && $reversedSessionIds->has($session->id) => 'reversed',
                $record !== null => $record->status->value,
                // Spec §8: excluded reads as "Excluded", never "Absent".
                $isExcluded => 'excluded',
                // Not yet scanned and the session may still be open — only
                // EndSession is allowed to decide Absent, so this stays
                // null (rendered as "Pending") rather than guessed at.
                default => null,
            };
        }

        return $statuses;
    }
}
