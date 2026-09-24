<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * student-exclusion-feature-plan.md §6: an exclusion can only be removed
 * while its own scope (the specific event/day/window it targets) hasn't
 * ended yet. Once that scope ends, the exclusion — and whatever it
 * caused to read "Excluded" — is locked in as history.
 */
final class ExclusionNotRemovableException extends RuntimeException
{
    public function __construct(string $message = 'This exclusion can no longer be removed because its schedule has already ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
