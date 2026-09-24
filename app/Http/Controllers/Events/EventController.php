<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\CreateEvent;
use App\Application\Actions\Events\DeleteEvent;
use App\Application\Actions\Events\UpdateEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\StoreEventRequest;
use App\Http\Requests\Events\UpdateEventRequest;
use App\Models\EventModel;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', EventModel::class);

        return response()->json(
            EventModel::with(['days.sessions', 'semester.academicYear', 'departments'])->orderByDesc('id')->get()
        );
    }

    public function store(StoreEventRequest $request, CreateEvent $createEvent)
    {
        $event = $createEvent($request->validated(), $request->user());

        return response()->json($event, 201);
    }

    /**
     * event-day-window-edit-delete-plan.md §4.1: name/description only,
     * guarded by event-not-ended (see UpdateEvent).
     */
    public function update(UpdateEventRequest $request, EventModel $event, UpdateEvent $updateEvent)
    {
        $updated = $updateEvent($event, $request->validated());

        return response()->json($updated);
    }

    /**
     * Deletes the event outright — meant for "I created this by
     * mistake," not for erasing a real event's history. No request body
     * needed (same reasoning as EndSessionController) — gate it
     * directly instead. DeleteEvent refuses if the event has ended, if
     * any session anywhere under it has started or ended, or if any
     * exclusion/report record is already tied to it.
     */
    public function destroy(EventModel $event, DeleteEvent $deleteEvent)
    {
        Gate::authorize('delete', EventModel::class);

        $deleteEvent($event);

        return response()->json(['event_id' => $event->id]);
    }
}
