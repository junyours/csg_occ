<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\DeleteWindow;
use App\Domain\Enums\WindowType;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\EventDay;
use Illuminate\Support\Facades\Gate;

class WindowController extends Controller
{
    /**
     * event-day-window-edit-delete-plan.md §4.3b: delete every
     * still-Scheduled check of one window_type on one day, in a single
     * call. No request body needed (same reasoning as
     * EndSessionController) — gate it directly instead. Same
     * two-role gate as a single-check delete (AttendanceSessionPolicy::delete),
     * since this is a convenience wrapper around the same underlying
     * power, not a distinct one.
     */
    public function destroy(EventDay $eventDay, WindowType $windowType, DeleteWindow $deleteWindow)
    {
        Gate::authorize('delete', AttendanceSession::class);

        $summary = $deleteWindow($eventDay, $windowType, request()->user());

        return response()->json($summary);
    }
}
