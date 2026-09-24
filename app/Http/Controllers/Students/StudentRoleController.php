<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\AssignStudentRole;
use App\Domain\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\UpdateStudentRoleRequest;
use App\Models\Student;

class StudentRoleController extends Controller
{
    public function update(UpdateStudentRoleRequest $request, Student $student, AssignStudentRole $assignStudentRole)
    {
        $updated = $assignStudentRole(
            $student,
            Role::from($request->validated('role')),
            $request->validated('department_id'),
            $request->validated('event_id'),
            $request->user(),
        );

        return response()->json($updated->fresh(['department', 'scAdminDepartment', 'officerEvent']));
    }
}
