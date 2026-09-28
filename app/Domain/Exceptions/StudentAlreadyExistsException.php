<?php

namespace App\Domain\Exceptions;

use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Single "Add student" hit an existing number; carries the record for update mode. */
final class StudentAlreadyExistsException extends RuntimeException
{
    public function __construct(private readonly Student $existing)
    {
        parent::__construct("Student ID already exists. You can update this student's enrollment instead.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'student_exists',
            'student' => $this->existing,
        ], 409);
    }
}
