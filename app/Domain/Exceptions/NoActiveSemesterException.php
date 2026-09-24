<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when an event is created but there's no active semester to attach
 * it to (no active academic year, or the active academic year has no
 * active semester). Mirrors InvalidCredentialsException's render() pattern.
 */
final class NoActiveSemesterException extends RuntimeException
{
    public function __construct(string $message = 'There is no active semester to create this event in.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
