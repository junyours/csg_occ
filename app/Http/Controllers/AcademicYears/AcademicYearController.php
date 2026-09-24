<?php

namespace App\Http\Controllers\AcademicYears;

use App\Application\Actions\AcademicYears\ActivateAcademicYear;
use App\Application\Actions\AcademicYears\CreateAcademicYear;
use App\Application\Actions\AcademicYears\DeactivateAcademicYear;
use App\Application\Actions\AcademicYears\UpdateAcademicYear;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcademicYears\StoreAcademicYearRequest;
use App\Http\Requests\AcademicYears\UpdateAcademicYearRequest;
use App\Models\AcademicYear;
use Illuminate\Support\Facades\Gate;

class AcademicYearController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', AcademicYear::class);

        return response()->json(
            AcademicYear::orderByDesc('start_date')->orderByDesc('id')->get()
        );
    }

    public function store(StoreAcademicYearRequest $request, CreateAcademicYear $createAcademicYear)
    {
        $academicYear = $createAcademicYear($request->validated(), $request->user());

        return response()->json($academicYear, 201);
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear, UpdateAcademicYear $updateAcademicYear)
    {
        return response()->json($updateAcademicYear($academicYear, $request->validated()));
    }

    public function activate(AcademicYear $academicYear, ActivateAcademicYear $activateAcademicYear)
    {
        Gate::authorize('update', AcademicYear::class);

        return response()->json($activateAcademicYear($academicYear));
    }

    public function deactivate(AcademicYear $academicYear, DeactivateAcademicYear $deactivateAcademicYear)
    {
        Gate::authorize('update', AcademicYear::class);

        return response()->json($deactivateAcademicYear($academicYear));
    }
}
