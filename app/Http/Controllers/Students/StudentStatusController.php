<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\UpdateStudentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateStudentStatusRequest;
use App\Models\Student;

class StudentStatusController extends Controller
{
    public function update(UpdateStudentStatusRequest $request, Student $student, UpdateStudentStatus $updateStudentStatus)
    {
        return response()->json($updateStudentStatus($student, $request->validated('status')));
    }
}
