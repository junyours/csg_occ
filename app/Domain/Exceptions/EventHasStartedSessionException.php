<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Guards App\Application\Actions\Events\DeleteEvent: an event can only be
 * deleted outright while every session under every one of its days is
 * still `Scheduled` (or no sessions/days exist at all) — the same
 * "nothing has actually happened yet" bar as EventDayHasStartedSessionException,
 * just widened to the whole event instead of a single day.
 */
final class EventHasStartedSessionException extends RuntimeException
{
    public function __construct(string $message = 'This event cannot be deleted because at least one of its sessions has already started or ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
