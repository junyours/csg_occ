<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Guards App\Application\Actions\Events\DeleteEvent. `exclusions.event_id`
 * and `report_generations.event_id` are both plain `constrained()` foreign
 * keys — no `cascadeOnDelete()`, no `nullOnDelete()` — specifically so
 * exclusion history and generated reports are never silently orphaned or
 * dropped. That means the DB itself would refuse a hard delete of an
 * EventModel row with any exclusion or report row still pointing at it
 * (even a `removed` exclusion, since it's never actually erased). Rather
 * than let that surface as a raw 500 from a foreign-key violation, this
 * is checked explicitly up front and reported as a normal 409.
 */
final class EventHasDependentRecordsException extends RuntimeException
{
    public function __construct(string $message = 'This event cannot be deleted because it already has exclusion or report records tied to it.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
