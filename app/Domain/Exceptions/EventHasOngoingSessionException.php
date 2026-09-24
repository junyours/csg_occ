<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when starting a session would leave two sessions of the *same*
 * event ongoing at once — e.g. Day 1 Morning Time Out is still open and
 * someone tries to start Day 1 Afternoon Time In on the same event. Only
 * one session per event may be ongoing at a time; the previous one must
 * be ended first. Sessions belonging to *different* events are unaffected
 * — two events can each have their own ongoing session at the same time.
 */
final class EventHasOngoingSessionException extends RuntimeException
{
    public function __construct(
        string $message = 'Another session in this event is already ongoing. End it before starting a new one.'
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
