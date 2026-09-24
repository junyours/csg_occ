<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Guards App\Application\Actions\Events\DeleteEvent. Unlike a removed
 * exclusion, a ReportGeneration row represents a real work product (a
 * file someone may have already downloaded), so this one is never
 * auto-cleaned up — there's no "reverse it and retry" path, the event
 * genuinely can't be deleted while a report exists for it.
 * `report_generations.event_id` is a plain `constrained()` FK with no
 * cascade, so without this check the delete would otherwise fail with a
 * raw database foreign-key error instead of a clean 409.
 */
final class EventHasReportsException extends RuntimeException
{
    public function __construct(string $message = 'This event cannot be deleted because a report has already been generated for it.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
