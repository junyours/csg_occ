<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class StaleQrCodeException extends RuntimeException
{
    public function __construct(string $message = 'This QR code has been superseded by a newer one.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
