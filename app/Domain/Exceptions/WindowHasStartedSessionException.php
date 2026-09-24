<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * event-day-window-edit-delete-plan.md §4.3b: deleting a whole window
 * (every AttendanceSession sharing one event_day_id + window_type) is
 * refused whole if even one of those checks (time-in or time-out) has
 * already started or ended — see
 * EventDay::windowHasAnyStartedOrEndedSession().
 */
final class WindowHasStartedSessionException extends RuntimeException
{
    public function __construct(string $message = 'This window cannot be deleted because at least one of its checks has already started or ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
