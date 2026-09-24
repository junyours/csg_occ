<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * event-day-window-edit-delete-plan.md §4.3a: a single check
 * (AttendanceSession row — e.g. just "Morning Time In") can only be
 * edited or deleted while it is still `Scheduled`. Once it's `Ongoing`
 * or `Ended`, both are refused. Distinct from SessionNotScheduledException
 * (which StartSession throws for the same underlying condition) so an
 * edit/delete failure and a start failure read as separate, purpose-
 * specific errors to API consumers.
 */
final class SessionAlreadyStartedException extends RuntimeException
{
    public function __construct(string $message = 'This session cannot be changed because it has already started or ended.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
