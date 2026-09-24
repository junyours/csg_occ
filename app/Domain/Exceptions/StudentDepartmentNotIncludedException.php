<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a student's own department isn't one this event is scoped
 * to (see EventModel::includesDepartment) — e.g. a BSBA student's QR
 * scanned into an event narrowed to BSIT + BEd only. Distinct from
 * StudentNotEligibleForAttendanceException (which is about the *role*,
 * not the department) so the officer sees a message that actually
 * explains what went wrong.
 */
final class StudentDepartmentNotIncludedException extends RuntimeException
{
    public function __construct(string $message = "This student's department is not included in this event.")
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
