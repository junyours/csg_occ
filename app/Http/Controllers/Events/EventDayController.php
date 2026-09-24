<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\CreateEventDay;
use App\Application\Actions\Events\DeleteEventDay;
use App\Application\Actions\Events\UpdateEventDay;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\StoreEventDayRequest;
use App\Http\Requests\Events\UpdateEventDayRequest;
use App\Models\EventDay;
use App\Models\EventModel;
use Illuminate\Support\Facades\Gate;

class EventDayController extends Controller
{
    public function store(StoreEventDayRequest $request, EventModel $event, CreateEventDay $createEventDay)
    {
        $day = $createEventDay($event, $request->validated());

        return response()->json($day, 201);
    }

    /**
     * event-day-window-edit-delete-plan.md §4.2: date only, guarded by
     * event-not-ended + no started/ended session under the day (see
     * UpdateEventDay). Date uniqueness within the event is enforced by
     * UpdateEventDayRequest.
     */
    public function update(UpdateEventDayRequest $request, EventDay $eventDay, UpdateEventDay $updateEventDay)
    {
        $updated = $updateEventDay($eventDay, $request->validated());

        return response()->json($updated);
    }

    /**
     * event-day-window-edit-delete-plan.md §4.2/§4.4: no request body
     * needed (same reasoning as EndSessionController) — gate it
     * directly instead. DeleteEventDay cascades Day/Window-scope
     * exclusion soft-removal before the actual delete.
     */
    public function destroy(EventDay $eventDay, DeleteEventDay $deleteEventDay)
    {
        Gate::authorize('deleteDay', EventModel::class);

        $summary = $deleteEventDay($eventDay, request()->user());

        return response()->json($summary);
    }
}
