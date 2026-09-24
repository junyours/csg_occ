<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class PhotoUploadLockedException extends RuntimeException
{
    public function __construct(string $message = 'Profile photo is locked while an attendance session is ongoing.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 423);
    }
}
