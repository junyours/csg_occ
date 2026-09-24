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

/**
 * The multi-event counterpart to BuildEventRosterReport: instead of one
 * event's sessions as columns, every session from every selected event
 * is laid out side by side, event by event, in the order the events
 * were given — "event 1 day1 morning/afternoon/evening ... event 2
 * day1 ..." (see the Reports screen's master-report mock). The roster
 * itself (who's in it, how it's grouped/sorted) works exactly like
 * BuildEventRosterReport — one shared roster, not one per event, since
 * the whole point of a master report is comparing the same students
 * across events rather than re-listing them per event.
 */
final class BuildMasterRosterReport
{
    private const WINDOW_ORDER = ['morning' => 1, 'afternoon' => 2, 'evening' => 3];

    private const CHECK_ORDER = ['time_in' => 1, 'time_out' => 2];

    /**
     * @param  Collection<int, EventModel>|array<int, EventModel>  $events  In display order —
     *                                        this is what decides left-to-right column order,
     *                                        not event id or start date.
     * @param  callable|null  $onGroupBuilt  Same per-group progress hook as
     *                                       BuildEventRosterReport; a no-op when null.
     * @return array{events: array, sessions: array, groups: array}
     */
    public function __invoke(
        iterable $events,
        ?int $departmentId = null,
        ?string $major = null,
        ?string $yearLevel = null,
        ?string $section = null,
        ?callable $onGroupBuilt = null,
    ): array {
        $events = collect($events)->values();

        $sessionMeta = $events
            ->flatMap(fn (EventModel $event) => $this->sessionsForEvent($event))
            ->values();

        $sessionIds = $sessionMeta->pluck('id');

        $roster = Student::where('role', Role::Student)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->when(filled($major), fn ($q) => $q->where('major', $major))
            ->when(filled($yearLevel), fn ($q) => $q->where('year_level', $yearLevel))
            ->when(filled($section), fn ($q) => $q->where('section', $section))
            ->with('department')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $rosterIds = $roster->pluck('id');

        $recordsBySession = AttendanceRecord::whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->get()
            ->groupBy('session_id')
            ->map(fn (Collection $records) => $records->keyBy('student_id'));

        $sessionsById = AttendanceSession::whereIn('id', $sessionIds)->get()->keyBy('id');
        $excludedBySession = $sessionIds->mapWithKeys(
            fn (int $sessionId) => [$sessionId => Exclusion::excludedStudentIdsForSession($sessionsById[$sessionId])]
        );

        // One fetch feeding both the money view and the reversed-state
        // view, so the two can't disagree about the same row.
        $penalties = AttendancePenalty::whereIn('session_id', $sessionIds)
            ->whereIn('student_id', $rosterIds)
            ->get()
            ->groupBy('student_id');

        $penaltyTotals = $penalties->map(
            fn (Collection $forStudent) => (float) $forStudent->where('is_reversed', false)->sum('amount')
        );

        // See BuildEventRosterReport for why this exists — sessions whose
        // Late/Absent penalty was later reversed should read "Reversed"
        // on the roster, not "Absent"/"Late".
        //
        // Guarded on nothing being left outstanding for that session:
        // attendance_penalties has no unique (student_id, session_id) key
        // and EndSession's firstOrCreate keys on reason as well, so one
        // session can hold both a reversed row and a live one. Flipping on
        // the mere existence of a reversal would then read "Reversed"
        // while the penalty column still charges for it.
        $reversedSessionIdsByStudent = $penalties->map(function (Collection $forStudent) {
            $activeSessionIds = $forStudent->where('is_reversed', false)->pluck('session_id')->flip();

            return $forStudent->where('is_reversed', true)
                ->pluck('session_id')
                ->reject(fn (int $sessionId) => $activeSessionIds->has($sessionId))
                ->flip();
        });

        $groups = $roster
            // See BuildEventRosterReport for why major is part of the key
            // — otherwise e.g. BSBA-FM-1A and BSBA-MM-1A would collide
            // into one group.
            ->groupBy(fn (Student $s) => sprintf(
                '%s|%s|%s|%s',
                $s->department?->code ?? '—',
                $s->major ?? '',
                $s->year_level ?? '—',
                $s->section ?? '—',
            ))
            ->map(function (Collection $students, string $key) use ($sessionMeta, $recordsBySession, $excludedBySession, $penaltyTotals, $reversedSessionIdsByStudent, $onGroupBuilt) {
                $group = $this->buildGroup(
                    $key, $students, $sessionMeta, $recordsBySession, $excludedBySession, $penaltyTotals, $reversedSessionIdsByStudent,
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
            'events' => $events->map(fn (EventModel $event) => ['id' => $event->id, 'name' => $event->name])->values()->all(),
            'sessions' => $sessionMeta->all(),
            'groups' => $groups,
        ];
    }

    /**
     * @return Collection<int, array{id: int, event_id: int, event_name: string, day_number: int, window_type: string, check_type: string, label: string}>
     */
    private function sessionsForEvent(EventModel $event): Collection
    {
        return AttendanceSession::whereHas('eventDay', fn ($q) => $q->where('event_id', $event->id))
            ->with('eventDay')
            ->get()
            ->sortBy(fn (AttendanceSession $s) => sprintf(
                '%03d-%d-%d',
                $s->eventDay->day_number,
                self::WINDOW_ORDER[$s->window_type->value] ?? 99,
                self::CHECK_ORDER[$s->check_type->value] ?? 99,
            ))
            ->values()
            ->map(fn (AttendanceSession $s) => [
                'id' => $s->id,
                'event_id' => $event->id,
                'event_name' => $event->name,
                'day_number' => $s->eventDay->day_number,
                'window_type' => $s->window_type->value,
                'check_type' => $s->check_type->value,
                'label' => $event->name.' — Day '.$s->eventDay->day_number.' — '.ucfirst($s->window_type->value)
                    .' — '.($s->check_type->value === 'time_in' ? 'Time In' : 'Time Out'),
            ]);
    }

    /**
     * @param  Collection<int, Student>  $students
     * @param  Collection<int, array>  $sessionMeta
     * @param  Collection<int, Collection>  $recordsBySession
     * @param  Collection<int, array<int>>  $excludedBySession
     * @param  Collection<int, float>  $penaltyTotals
     * @param  Collection<int, Collection<int, int>>  $reversedSessionIdsByStudent
     */
    private function buildGroup(
        string $key,
        Collection $students,
        Collection $sessionMeta,
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
                    $student, $sessionMeta, $recordsBySession, $excludedBySession,
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
     * @param  Collection<int, array>  $sessionMeta
     * @param  Collection<int, Collection>  $recordsBySession
     * @param  Collection<int, array<int>>  $excludedBySession
     * @param  Collection<int, int>  $reversedSessionIds  Session ids (as keys)
     *                                                     for which this
     *                                                     student's Late/Absent
     *                                                     penalty was reversed.
     * @return array<int, ?string>
     */
    private function sessionStatusesFor(
        Student $student,
        Collection $sessionMeta,
        Collection $recordsBySession,
        Collection $excludedBySession,
        Collection $reversedSessionIds,
    ): array {
        $statuses = [];

        foreach ($sessionMeta as $session) {
            $sessionId = $session['id'];
            $isExcluded = in_array($student->id, $excludedBySession->get($sessionId, []), true);
            $record = $recordsBySession->get($sessionId, collect())->get($student->id);
            $isReversed = $reversedSessionIds->has($sessionId);

            // Identical precedence to BuildEventRosterReport — the two
            // reports render the same cell and must never disagree:
            //
            //  1. a stored `excluded` record wins outright, reversal
            //     included (§6a point 3: EndSession froze it in, and it
            //     was never a charge to forgive);
            //  2. any other real record beats the *live* exclusion flag —
            //     a student who genuinely scanned before being excluded
            //     keeps that Present/Late/Absent outcome (§2 rule 4's
            //     "mid-window guard"), it is never rewritten to
            //     "Excluded" after the fact;
            //  3. reversed outranks a stored Absent/Late, since the
            //     roster is penalty-facing and the charge was undone;
            //  4. only a student with no record at all reads from the
            //     live exclusion flag.
            $statuses[$sessionId] = match (true) {
                $record !== null && $record->status === AttendanceStatus::Excluded => 'excluded',
                $record !== null && $isReversed => 'reversed',
                $record !== null => $record->status->value,
                $isExcluded => 'excluded',
                default => null,
            };
        }

        return $statuses;
    }
}
