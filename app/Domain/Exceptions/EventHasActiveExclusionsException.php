<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Guards App\Application\Actions\Events\DeleteEvent. This one is
 * resolvable by the person hitting it: remove the active exclusion(s)
 * from the Manage Exclusions screen (App\Application\Actions\Exclusions\RemoveExclusion),
 * then retry the delete — an event with only *removed* exclusions on it
 * is allowed through.
 */
final class EventHasActiveExclusionsException extends RuntimeException
{
    public function __construct(string $message = 'This event still has active exclusions. Remove them first, then delete the event.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
