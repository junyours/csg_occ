<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class InvalidCredentialsException extends RuntimeException
{
    /**
     * @param  int  $status  401 for a failed login attempt (not authenticated
     *                        at all); 422 for a wrong current_password on an
     *                        already-authenticated change-password request.
     */
    public function __construct(string $message = 'Invalid credentials.', private readonly int $status = 401)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
