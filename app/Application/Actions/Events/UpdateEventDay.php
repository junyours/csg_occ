<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Models\EventDay;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class UpdateEventDay
{
    /**
     * event-day-window-edit-delete-plan.md §4.2 / §4.1a: editing a Day's
     * date requires, in order:
     *  1. The parent event has not been ended (checked first — this
     *     overrides everything else, per §4.1a).
     *  2. No session under this day is Ongoing or Ended (hasAnyStartedOrEndedSession,
     *     not hasEnded() — see Bug #1 / the model docblock).
     *
     * Date-uniqueness-within-the-event ("§2 confirmed") is enforced by
     * UpdateEventDayRequest's `Rule::unique(...)->ignore($eventDay->id)`
     * before this action ever runs, so it isn't re-checked here.
     *
     * @param  array{date: string}  $data
     *
     * @throws EventAlreadyEndedException
     * @throws EventDayHasStartedSessionException
     */
    public function __invoke(EventDay $eventDay, array $data): EventDay
    {
        return DB::transaction(function () use ($eventDay, $data) {
            // Lock the day row first (mirrors RemoveExclusion's ordering:
            // lock the row this action is fundamentally about before
            // locking anything it merely needs to check).
            $locked = EventDay::whereKey($eventDay->id)->lockForUpdate()->first();

            // Then the event row — first check per §4.1a, but locked
            // second here since the day row is what a concurrent
            // "delete this day" would also lock first.
            $event = EventModel::whereKey($locked->event_id)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot update this day because its event has already been ended.'
                );
            }

            if ($locked->hasAnyStartedOrEndedSession(forUpdate: true)) {
                throw new EventDayHasStartedSessionException(
                    'Cannot update this day because at least one of its sessions has already started or ended.'
                );
            }

            $locked->update(['date' => $data['date']]);

            return $locked->fresh(['event', 'sessions']);
        });
    }
}
