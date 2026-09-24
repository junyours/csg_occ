<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\EventDay;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class CreateEventDay
{
    /**
     * event-day-window-edit-delete-plan.md §8 point 1 (resolved: bundled
     * in) — an ended event is fully locked, not just for its existing
     * days/sessions but for adding new ones too. Same §4.1a pattern as
     * every Update/Delete action: lock the event row and check status
     * before doing anything else.
     *
     * @param array{date: string, day_number: int} $data
     *
     * @throws EventAlreadyEndedException
     */
    public function __invoke(EventModel $event, array $data): EventDay
    {
        return DB::transaction(function () use ($event, $data) {
            $lockedEvent = EventModel::whereKey($event->id)->lockForUpdate()->first();

            if ($lockedEvent->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot add a new day because this event has already been ended.'
                );
            }

            return EventDay::create([
                'event_id' => $lockedEvent->id,
                'date' => $data['date'],
                'day_number' => $data['day_number'],
            ]);
        });
    }
}
