<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The reset token is missing, wrong, already used, expired, or belongs to a
 * different student. 410 Gone (not 401 — the frontend signs the user out on
 * a 401, and there's no session here to sign out of).
 */
final class InvalidPasswordResetSessionException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This reset session has expired. Please start over.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 410);
    }
}
