<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\ForceDeleteEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\ForceDeleteEventRequest;
use App\Models\EventModel;

class EventForceDeleteController extends Controller
{
    /**
     * System Admin only. Removes an ended event and all of its attendance
     * data — see ForceDeleteEvent.
     */
    public function store(ForceDeleteEventRequest $request, EventModel $event, ForceDeleteEvent $forceDeleteEvent)
    {
        $result = $forceDeleteEvent($event, $request->validated()['confirm_name'], $request->user());

        return response()->json($result);
    }
}
