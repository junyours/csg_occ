<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventDayDateNotUniqueException;
use App\Domain\Exceptions\EventDayHasStartedSessionException;
use App\Domain\Exceptions\EventDayNotInEventException;
use App\Models\EventDay;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class RescheduleEventDays
{
    /**
     * event-day-window-edit-delete-plan.md §4.5: a batch, all-or-nothing
     * date shift across several days of one event — the resolution path
     * when a plain single-day edit (UpdateEventDay) would collide with
     * another day's date. Everything is re-validated with fresh
     * `lockForUpdate()` reads at commit time (Bug #11) rather than
     * trusting whatever the Reschedule screen showed when it was
     * opened — another admin could have started a session on one of the
     * targeted days, or on an untouched day whose date would otherwise
     * collide, in the meantime.
     *
     * Validation, in order:
     *  1. Event not ended (§4.1a).
     *  2. Every targeted event_day_id actually belongs to this event.
     *  3. Every targeted day is still eligible — no session under it is
     *     Ongoing or Ended (same guard as UpdateEventDay, re-checked
     *     fresh here rather than trusted from the screen's earlier
     *     snapshot).
     *  4. The *final* date set — the new dates being submitted, plus
     *     the untouched dates of every other day in the event (frozen
     *     or simply not included in this batch) — has no duplicates.
     *     This is the real uniqueness check; §4.2's single-day-edit
     *     check is just this same check run against a batch of one.
     *
     * All-or-nothing: if any row fails, nothing is saved (the whole
     * batch runs inside one DB::transaction()).
     *
     * Deliberately doesn't touch Exclusion rows at all — a day's
     * exclusions target it by event_day_id (its identity), not by its
     * date value, so moving a day's date never requires touching,
     * re-validating, or re-checking anything exclusion-related (§5a).
     *
     * @param  array<int, array{event_day_id: int, date: string}>  $targets
     * @return array<int, EventDay> keyed by event_day_id, every day in
     *                               the event (moved or not) with its
     *                               resulting date
     *
     * @throws EventAlreadyEndedException
     * @throws EventDayNotInEventException
     * @throws EventDayHasStartedSessionException
     * @throws EventDayDateNotUniqueException
     */
    public function __invoke(EventModel $event, array $targets): array
    {
        return DB::transaction(function () use ($event, $targets) {
            $lockedEvent = EventModel::whereKey($event->id)->lockForUpdate()->first();

            if ($lockedEvent->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot reschedule this event because it has already been ended.'
                );
            }

            // Lock and load every day in the event — the "untouched"
            // days' current dates are just as much a part of the final
            // uniqueness check as the days actually being moved.
            $allDays = EventDay::query()
                ->where('event_id', $lockedEvent->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $targetsByDayId = collect($targets)->keyBy('event_day_id');

            foreach ($targetsByDayId as $dayId => $target) {
                $day = $allDays->get($dayId);

                if (! $day) {
                    throw new EventDayNotInEventException(
                        "Day #{$dayId} does not belong to this event."
                    );
                }

                if ($day->hasAnyStartedOrEndedSession(forUpdate: true)) {
                    throw new EventDayHasStartedSessionException(
                        "Day #{$dayId} cannot be rescheduled because at least one of its sessions has already started or ended."
                    );
                }
            }

            // Final date set: the new date for every targeted day, the
            // existing date for every day left untouched by this batch.
            $finalDatesByDayId = $allDays->map(
                fn (EventDay $day) => $targetsByDayId->has($day->id)
                    ? $targetsByDayId->get($day->id)['date']
                    : $day->date->format('Y-m-d')
            );

            $duplicateDate = $finalDatesByDayId
                ->countBy(fn (string $date) => $date)
                ->filter(fn (int $count) => $count > 1)
                ->keys()
                ->first();

            if ($duplicateDate !== null) {
                throw new EventDayDateNotUniqueException(
                    "More than one day would end up dated {$duplicateDate} — every day in an event must have a unique date."
                );
            }

            foreach ($targetsByDayId as $dayId => $target) {
                $allDays->get($dayId)->update(['date' => $target['date']]);
            }

            return EventDay::query()
                ->where('event_id', $lockedEvent->id)
                ->get()
                ->keyBy('id')
                ->all();
        }, attempts: 3);
    }
}
