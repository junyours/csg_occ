<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Guards App\Application\Actions\Events\ForceDeleteEvent: the System Admin
 * override only exists for events that have already ended (the case
 * DeleteEvent refuses). A live or scheduled event keeps its normal protection.
 */
final class EventNotEndedException extends RuntimeException
{
    public function __construct(string $message = 'Only an ended event can be force-deleted. Use the normal delete for an event that has not started.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
