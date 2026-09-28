<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The code has been guessed wrong too many times. Answered as a 429 with
 * Retry-After — the same shape as the login throttle, so the frontend can
 * treat it identically — where Retry-After is how long until the code
 * expires (and therefore until a fresh one can be requested).
 */
final class PasswordResetCodeLockedException extends RuntimeException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct('Too many incorrect attempts. Ask for a new code.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(
            ['message' => $this->getMessage(), 'retry_after' => $this->retryAfterSeconds],
            429,
            ['Retry-After' => (string) $this->retryAfterSeconds],
        );
    }
}
