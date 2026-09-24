<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\UpdateStudentPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateStudentPhotoRequest;
use App\Models\Student;

class StudentPhotoController extends Controller
{
    public function update(UpdateStudentPhotoRequest $request, Student $student, UpdateStudentPhoto $updateStudentPhoto)
    {
        $updated = $updateStudentPhoto($student, $request->file('photo'));

        return response()->json($updated);
    }
}
