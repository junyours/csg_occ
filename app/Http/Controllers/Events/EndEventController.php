<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Events\EndEvent;
use App\Http\Controllers\Controller;
use App\Models\EventModel;
use Illuminate\Support\Facades\Gate;

class EndEventController extends Controller
{
    public function store(EventModel $event, EndEvent $endEvent)
    {
        // No request body needed — same reasoning as EndSessionController.
        Gate::authorize('end', $event);

        $summary = $endEvent($event);

        return response()->json(array_merge($summary, [
            'status' => $event->fresh()->status->value,
        ]));
    }
}
