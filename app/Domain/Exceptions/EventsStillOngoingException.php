<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Semester deactivation blocked by ongoing events; names them. */
final class EventsStillOngoingException extends RuntimeException
{
    /** @param array<int, string> $eventNames */
    public function __construct(private readonly array $eventNames = [])
    {
        parent::__construct('End these events before deactivating the semester: '.implode(', ', $eventNames).'.');
    }

    /** @return array<int, string> */
    public function eventNames(): array
    {
        return $this->eventNames;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'events' => $this->eventNames], 409);
    }
}
