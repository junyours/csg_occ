<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * student-exclusion-feature-plan.md §5 step 4: no duplicate active
 * exclusion for the same student + the exact same scope.
 */
final class DuplicateExclusionException extends RuntimeException
{
    public function __construct(string $message = 'This student already has an active exclusion for this exact scope.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
