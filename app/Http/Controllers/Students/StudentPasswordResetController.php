<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\ResetStudentPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\ResetStudentPasswordRequest;
use App\Models\Student;

class StudentPasswordResetController extends Controller
{
    public function store(ResetStudentPasswordRequest $request, Student $student, ResetStudentPassword $resetStudentPassword)
    {
        $resetStudentPassword($student, $request->user());

        return response()->json(['message' => 'Password reset to the default. The student must change it at next sign-in.']);
    }
}
