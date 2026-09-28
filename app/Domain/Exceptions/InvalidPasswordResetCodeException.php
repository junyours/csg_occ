<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Wrong, expired, already-used or nonexistent code. One message for all of
 * them on purpose — the response never says which, or whether the student
 * exists at all.
 */
final class InvalidPasswordResetCodeException extends RuntimeException
{
    /**
     * @param  int|null  $attemptsRemaining  Only set for a wrong guess against a real, live code.
     */
    public function __construct(private readonly ?int $attemptsRemaining = null)
    {
        parent::__construct('Incorrect or expired code.');
    }

    public function render(Request $request): JsonResponse
    {
        $body = ['message' => $this->getMessage()];

        if ($this->attemptsRemaining !== null) {
            $body['attempts_remaining'] = $this->attemptsRemaining;
        }

        return response()->json($body, 422);
    }
}
