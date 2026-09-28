<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\CreateEvent;
use App\Application\Actions\Events\DeleteEvent;
use App\Application\Actions\Events\UpdateEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\StoreEventRequest;
use App\Http\Requests\Events\UpdateEventRequest;
use App\Domain\Support\SemesterScope;
use App\Models\EventModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventController extends Controller
{
    /** Current semester by default (students always); `semester_id` / `scope=all` for others. */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', EventModel::class);

        $filters = $request->validate([
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'scope' => ['nullable', 'string', 'in:all'],
        ]);

        $semesterId = SemesterScope::resolve($request->user(), $filters['scope'] ?? null, $filters['semester_id'] ?? null);

        return response()->json(
            SemesterScope::events(
                EventModel::with(['days.sessions', 'semester.academicYear', 'departments']),
                $semesterId,
                'events.semester_id',
            )->orderByDesc('id')->get()
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
