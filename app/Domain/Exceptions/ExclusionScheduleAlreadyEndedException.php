<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * student-exclusion-feature-plan.md §2 rule 4 / §6a race-condition note:
 * an exclusion can't be added against an event/day/window that has
 * already ended, checked atomically at commit time — not just against
 * whatever the UI displayed when the form was opened.
 */
final class ExclusionScheduleAlreadyEndedException extends RuntimeException
{
    public function __construct(string $message = 'Cannot add an exclusion for a schedule that has already ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
