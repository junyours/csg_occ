<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * student-exclusion-feature-plan.md §2 rule 3: CSG can only exclude a
 * student from a Day/Window that already exists on the event — the
 * schedule must be created first.
 */
final class WindowNotFoundOnDayException extends RuntimeException
{
    public function __construct(string $message = 'That window does not exist on the given day yet.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
