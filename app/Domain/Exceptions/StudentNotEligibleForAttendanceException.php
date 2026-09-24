<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a QR code belonging to a staff account (System Admin, CSG
 * Admin, or SC Admin) is scanned. These roles run events rather than
 * attend them, so — unlike Officer, who is staff but still has their own
 * attendance tracked — they're never eligible to have an attendance
 * record created at all.
 */
final class StudentNotEligibleForAttendanceException extends RuntimeException
{
    public function __construct(string $message = 'This account is not eligible for attendance tracking.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
