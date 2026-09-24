<?php

namespace App\Application\Actions\Sessions;

use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\ExclusionAlreadyRemovedException;
use App\Domain\Exceptions\ExclusionNotRemovableException;
use App\Domain\Exceptions\SessionAlreadyStartedException;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class DeleteSession
{
    public function __construct(private readonly RemoveExclusion $removeExclusion) {}

    /**
     * event-day-window-edit-delete-plan.md §4.3a/§4.4: delete a single
     * check (e.g. just "Morning Time In") while it is still `Scheduled`.
     * §4.1a's event-ended lock is checked first.
     *
     * §4.4's cascade: deleting one check while its sibling check (the
     * other half of the same window) still exists must NOT revoke the
     * window's exclusion — a student excluded from "Morning" is still
     * correctly excluded from the surviving check. Only once this
     * delete removes the *last* remaining check of that window
     * (`EventDay::hasWindow()` would go from true to false) does the
     * Window-scope exclusion get soft-removed. The "does a sibling still
     * exist" check happens before the delete, under the same lock, so a
     * concurrent sibling create/delete can't race past it.
     *
     * @return array{session_id: int, exclusions_removed: int}
     *
     * @throws EventAlreadyEndedException
     * @throws SessionAlreadyStartedException
     */
    public function __invoke(AttendanceSession $session, Student $removedBy): array
    {
        return DB::transaction(function () use ($session, $removedBy) {
            $locked = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            $event = EventModel::whereKey($locked->eventDay->event_id)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot delete this session because its event has already been ended.'
                );
            }

            if ($locked->status !== SessionStatus::Scheduled) {
                throw new SessionAlreadyStartedException;
            }

            $eventDayId = $locked->event_day_id;
            $windowType = $locked->window_type;

            $siblingExists = AttendanceSession::query()
                ->where('event_day_id', $eventDayId)
                ->where('window_type', $windowType->value)
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->exists();

            $locked->delete();

            $exclusionsRemoved = 0;

            if (! $siblingExists) {
                // This was the last check of that window — the window
                // itself no longer exists on this day, so any active
                // Window-scope exclusion targeting it is now dangling
                // (Bug #2's cascade problem, applied at the single-check
                // level) and must be soft-removed the same way §4.4
                // handles a whole Day delete.
                $matchingExclusionIds = Exclusion::query()
                    ->where('event_day_id', $eventDayId)
                    ->where('window_type', $windowType->value)
                    ->where('scope', ExclusionScope::Window->value)
                    ->where('status', ExclusionStatus::Active->value)
                    ->pluck('id');

                foreach ($matchingExclusionIds as $exclusionId) {
                    $exclusion = Exclusion::find($exclusionId);

                    if (! $exclusion) {
                        continue;
                    }

                    try {
                        ($this->removeExclusion)($exclusion, $removedBy);
                        $exclusionsRemoved++;
                    } catch (ExclusionAlreadyRemovedException|ExclusionNotRemovableException) {
                        // Same reasoning as DeleteEventDay — not this
                        // action's problem.
                    }
                }
            }

            return [
                'session_id' => $session->id,
                'exclusions_removed' => $exclusionsRemoved,
            ];
        }, attempts: 3);
    }
}
