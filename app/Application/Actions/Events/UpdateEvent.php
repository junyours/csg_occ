<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class UpdateEvent
{
    /**
     * event-day-window-edit-delete-plan.md §4.1: name/description carry
     * no scheduling semantics, so the only gate is "has the event
     * ended" — no session/day lifecycle state to check, unlike the
     * Day/Session update actions below.
     *
     * @param  array{name?: string, description?: string|null}  $data
     *
     * @throws EventAlreadyEndedException
     */
    public function __invoke(EventModel $event, array $data): EventModel
    {
        return DB::transaction(function () use ($event, $data) {
            // Lock the row so a concurrent "end event" and this edit
            // can't race past each other's checks — same pattern as
            // every other action touching an event's status.
            $locked = EventModel::whereKey($event->id)->lockForUpdate()->first();

            if ($locked->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot update this event because it has already been ended.'
                );
            }

            $locked->update(array_intersect_key($data, array_flip(['name', 'description'])));

            return $locked->fresh(['departments', 'semester.academicYear']);
        });
    }
}
