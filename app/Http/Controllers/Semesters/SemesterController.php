<?php

namespace App\Http\Controllers\Semesters;

use App\Application\Actions\Semesters\ActivateSemester;
use App\Application\Actions\Semesters\CreateSemester;
use App\Application\Actions\Semesters\DeactivateSemester;
use App\Application\Actions\Semesters\UpdateSemester;
use App\Http\Controllers\Controller;
use App\Http\Requests\Semesters\StoreSemesterRequest;
use App\Http\Requests\Semesters\UpdateSemesterRequest;
use App\Models\AcademicYear;
use App\Models\Semester;
use Illuminate\Support\Facades\Gate;

class SemesterController extends Controller
{
    public function index(AcademicYear $academicYear)
    {
        Gate::authorize('viewAny', Semester::class);

        return response()->json(
            $academicYear->semesters()->orderBy('id')->get()
        );
    }

    public function store(StoreSemesterRequest $request, AcademicYear $academicYear, CreateSemester $createSemester)
    {
        $semester = $createSemester($academicYear, $request->validated(), $request->user());

        return response()->json($semester, 201);
    }

    public function update(UpdateSemesterRequest $request, Semester $semester, UpdateSemester $updateSemester)
    {
        return response()->json($updateSemester($semester, $request->validated()));
    }

    public function activate(Semester $semester, ActivateSemester $activateSemester)
    {
        Gate::authorize('update', Semester::class);

        return response()->json($activateSemester($semester));
    }

    public function deactivate(Semester $semester, DeactivateSemester $deactivateSemester)
    {
        Gate::authorize('update', Semester::class);

        return response()->json($deactivateSemester($semester));
    }
}
