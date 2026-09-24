<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\RescheduleEventDays;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RescheduleEventDaysRequest;
use App\Models\EventModel;

class RescheduleEventDaysController extends Controller
{
    /**
     * event-day-window-edit-delete-plan.md §4.5: batch, all-or-nothing
     * date shift across several days of one event — the resolution path
     * for the §4.2 single-day-edit date collision.
     */
    public function update(RescheduleEventDaysRequest $request, EventModel $event, RescheduleEventDays $rescheduleEventDays)
    {
        $days = $rescheduleEventDays($event, $request->validated()['targets']);

        return response()->json(
            collect($days)->values()->sortBy('day_number')->values()
        );
    }
}
