<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * event-day-window-edit-delete-plan.md §4.2: a Day's date can only be
 * edited, and the Day itself only deleted, while every session under it
 * is still `Scheduled` — i.e. nothing has started or ended yet. This is
 * a different (wider) check than "has this day fully ended"
 * (EventDay::hasEnded()) — see EventDay::hasAnyStartedOrEndedSession().
 */
final class EventDayHasStartedSessionException extends RuntimeException
{
    public function __construct(string $message = 'This day cannot be changed because at least one of its sessions has already started or ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
