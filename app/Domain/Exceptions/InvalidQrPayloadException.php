<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class InvalidQrPayloadException extends RuntimeException
{
    public function __construct(string $message = 'Invalid QR payload.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
