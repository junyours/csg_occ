<?php

namespace App\Http\Controllers\Departments;

use App\Application\Actions\Departments\CreateDepartment;
use App\Application\Actions\Departments\DeleteDepartment;
use App\Application\Actions\Departments\UpdateDepartment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Departments\StoreDepartmentRequest;
use App\Http\Requests\Departments\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Department::class);

        return response()->json(Department::orderBy('name')->get());
    }

    public function store(StoreDepartmentRequest $request, CreateDepartment $createDepartment)
    {
        $department = $createDepartment($request->validated());

        return response()->json($department, 201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department, UpdateDepartment $updateDepartment)
    {
        $updated = $updateDepartment($department, $request->validated());

        return response()->json($updated);
    }

    public function destroy(Department $department, DeleteDepartment $deleteDepartment)
    {
        // No dedicated FormRequest for delete (no body to validate, same
        // reasoning as ExclusionController::destroy) — gate it directly
        // instead. DeleteDepartment itself throws DepartmentHasStudentsException
        // (409) when the department still has students.
        Gate::authorize('delete', Department::class);

        $deleteDepartment($department);

        return response()->noContent();
    }
}
