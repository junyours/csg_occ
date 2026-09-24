<?php

namespace App\Application\Actions\Sessions;

use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\ExclusionAlreadyRemovedException;
use App\Domain\Exceptions\ExclusionNotRemovableException;
use App\Domain\Exceptions\WindowHasStartedSessionException;
use App\Domain\Exceptions\WindowNotFoundOnDayException;
use App\Models\AttendanceSession;
use App\Models\EventDay;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class DeleteWindow
{
    public function __construct(private readonly RemoveExclusion $removeExclusion) {}

    /**
     * event-day-window-edit-delete-plan.md §4.3b: delete every
     * still-`Scheduled` check (time-in and/or time-out) of one
     * `window_type` on one day, in a single call. Guard: **every**
     * session sharing that (event_day_id, window_type) must be
     * Scheduled — if even one has started or ended, the whole window
     * delete is refused (§4.1a's event-ended lock is checked first, as
     * always).
     *
     * Bug #6: a window isn't guaranteed to have exactly two rows — it
     * might have only ever had a Time In configured, or (already
     * impossible to reach past the guard, but handled anyway) neither.
     * This queries whatever's actually there and deletes it, rather
     * than assuming a fixed shape. If nothing exists for this
     * window_type on this day at all, there's no window to delete.
     *
     * §4.4: unlike a single-check delete (DeleteSession), this always
     * makes the window disappear entirely, so any active Window-scope
     * exclusion targeting it is always soft-removed here — there's no
     * "did a sibling survive" branch, because nothing survives.
     *
     * @return array{event_day_id: int, window_type: string, sessions_deleted: int, exclusions_removed: int}
     *
     * @throws EventAlreadyEndedException
     * @throws WindowHasStartedSessionException
     * @throws WindowNotFoundOnDayException
     */
    public function __invoke(EventDay $eventDay, WindowType $windowType, Student $removedBy): array
    {
        return DB::transaction(function () use ($eventDay, $windowType, $removedBy) {
            // Lock the day row (Bug #4): serializes this against a
            // concurrent "create a new sibling session in this window"
            // request, not just the sibling rows that already exist.
            $lockedDay = EventDay::whereKey($eventDay->id)->lockForUpdate()->first();

            $event = EventModel::whereKey($lockedDay->event_id)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot delete this window because its event has already been ended.'
                );
            }

            $windowSessions = AttendanceSession::query()
                ->where('event_day_id', $lockedDay->id)
                ->where('window_type', $windowType->value)
                ->lockForUpdate()
                ->get();

            if ($windowSessions->isEmpty()) {
                throw new WindowNotFoundOnDayException;
            }

            if ($lockedDay->windowHasAnyStartedOrEndedSession($windowType, forUpdate: true)) {
                throw new WindowHasStartedSessionException;
            }

            foreach ($windowSessions as $windowSession) {
                $windowSession->delete();
            }

            $matchingExclusionIds = Exclusion::query()
                ->where('event_day_id', $lockedDay->id)
                ->where('window_type', $windowType->value)
                ->where('scope', ExclusionScope::Window->value)
                ->where('status', ExclusionStatus::Active->value)
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
                    // Same reasoning as DeleteEventDay/DeleteSession.
                }
            }

            return [
                'event_day_id' => $lockedDay->id,
                'window_type' => $windowType->value,
                'sessions_deleted' => $windowSessions->count(),
                'exclusions_removed' => $exclusionsRemoved,
            ];
        }, attempts: 3);
    }
}
