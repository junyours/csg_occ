<?php

namespace App\Http\Controllers\Departments;

use App\Application\Actions\Departments\UpdateDepartmentLogo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Departments\UpdateDepartmentLogoRequest;
use App\Models\Department;

class DepartmentLogoController extends Controller
{
    public function update(UpdateDepartmentLogoRequest $request, Department $department, UpdateDepartmentLogo $updateDepartmentLogo)
    {
        $updated = $updateDepartmentLogo($department, $request->file('logo'));

        return response()->json($updated);
    }
}
