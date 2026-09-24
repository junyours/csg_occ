<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Domain\Exceptions\ExclusionAlreadyRemovedException;
use App\Domain\Exceptions\ExclusionNotRemovableException;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class DeleteEventDay
{
    public function __construct(private readonly RemoveExclusion $removeExclusion) {}

    /**
     * event-day-window-edit-delete-plan.md §4.2/§4.4: delete a Day only
     * while every session under it is still Scheduled (or none exist),
     * and only while its parent event hasn't ended (§4.1a, checked
     * first). Before the actual delete, every ACTIVE exclusion targeting
     * this day is soft-removed — both Day-scope exclusions for this day
     * and every Window-scope exclusion for this day (any window_type) —
     * so `exclusions.event_day_id`'s `nullOnDelete` FK never gets the
     * chance to leave a dangling, unexplainable "active" row pointing at
     * nothing (§2's cascade audit / Bug #2). Event-scope exclusions are
     * left untouched — they aren't tied to a specific day.
     *
     * Deleting the row itself is left to the DB's own
     * `cascadeOnDelete()` on attendance_sessions.event_day_id (§2): safe
     * here specifically because the guard above only ever allows this
     * when every session under the day is Scheduled, so there are no
     * attendance_records/attendance_penalties rows to worry about
     * losing.
     *
     * `attempts: 3` (Bug #12): this transaction locks Event then
     * Exclusion rows (via RemoveExclusion), while RemoveExclusion called
     * directly elsewhere (e.g. the Manage Exclusions screen) locks
     * Exclusion then Event — a lock-order inversion that can deadlock
     * under real concurrency. Retrying absorbs it instead of surfacing a
     * raw 500.
     *
     * @return array{event_day_id: int, exclusions_removed: int}
     *
     * @throws EventAlreadyEndedException
     * @throws EventDayHasStartedSessionException
     */
    public function __invoke(EventDay $eventDay, Student $removedBy): array
    {
        return DB::transaction(function () use ($eventDay, $removedBy) {
            $locked = EventDay::whereKey($eventDay->id)->lockForUpdate()->first();

            $event = EventModel::whereKey($locked->event_id)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot delete this day because its event has already been ended.'
                );
            }

            if ($locked->hasAnyStartedOrEndedSession(forUpdate: true)) {
                throw new EventDayHasStartedSessionException(
                    'Cannot delete this day because at least one of its sessions has already started or ended.'
                );
            }

            $matchingExclusionIds = Exclusion::query()
                ->where('event_day_id', $locked->id)
                ->where('status', ExclusionStatus::Active->value)
                ->whereIn('scope', [ExclusionScope::Day->value, ExclusionScope::Window->value])
                ->pluck('id');

            $exclusionsRemoved = 0;

            foreach ($matchingExclusionIds as $exclusionId) {
                $exclusion = Exclusion::find($exclusionId);

                if (! $exclusion) {
                    continue;
                }

                try {
                    ($this->removeExclusion)($exclusion, $removedBy);
                    $exclusionsRemoved++;
                } catch (ExclusionAlreadyRemovedException|ExclusionNotRemovableException) {
                    // Already removed by someone else, or (shouldn't
                    // happen given the guard above, but defensively) its
                    // scope somehow already ended — not this action's
                    // problem either way.
                }
            }

            $locked->delete();

            return [
                'event_day_id' => $eventDay->id,
                'exclusions_removed' => $exclusionsRemoved,
            ];
        }, attempts: 3);
    }
}   
