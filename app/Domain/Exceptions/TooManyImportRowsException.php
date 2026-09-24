<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class TooManyImportRowsException extends RuntimeException
{
    public function __construct(int $max, int $actual)
    {
        parent::__construct(
            "This file has {$actual} data rows, over the {$max}-row limit for a single import. ".
            'Split it into smaller files and import them separately.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
