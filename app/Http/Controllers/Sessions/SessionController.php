<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\CreateSession;
use App\Application\Actions\Sessions\DeleteSession;
use App\Application\Actions\Sessions\UpdateSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sessions\StoreSessionRequest;
use App\Http\Requests\Sessions\UpdateSessionRequest;
use App\Models\AttendanceSession;
use App\Models\EventDay;
use Illuminate\Support\Facades\Gate;

class SessionController extends Controller
{
    public function store(StoreSessionRequest $request, EventDay $eventDay, CreateSession $createSession)
    {
        $session = $createSession($eventDay, $request->validated());

        return response()->json($session, 201);
    }

    /**
     * event-day-window-edit-delete-plan.md §4.3a: a single check, only
     * while it's still Scheduled (see UpdateSession).
     */
    public function update(UpdateSessionRequest $request, AttendanceSession $session, UpdateSession $updateSession)
    {
        $updated = $updateSession($session, $request->validated());

        return response()->json($updated);
    }

    /**
     * event-day-window-edit-delete-plan.md §4.3a/§4.4: no request body
     * needed (same reasoning as EndSessionController) — gate it
     * directly instead. DeleteSession cascades the window's exclusion
     * soft-removal if this was its last remaining check.
     */
    public function destroy(AttendanceSession $session, DeleteSession $deleteSession)
    {
        Gate::authorize('delete', AttendanceSession::class);

        $summary = $deleteSession($session, request()->user());

        return response()->json($summary);
    }
}
