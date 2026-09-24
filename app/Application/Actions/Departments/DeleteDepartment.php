<?php

namespace App\Application\Actions\Departments;

use App\Domain\Exceptions\DepartmentHasStudentsException;
use App\Models\Department;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DeleteDepartment
{
    /**
     * Only a department nobody was ever actually assigned to (e.g. one
     * created by mistake — wrong name/code, duplicate) is deletable.
     * Guards against both a plain student's home department and an SC
     * Admin's administered department (sc_admin_department_id) — both
     * columns carry a DB-level foreign key to departments, so without
     * this check the delete would otherwise fail with a raw SQL
     * constraint error instead of a clean, explainable one.
     */
    public function __invoke(Department $department): void
    {
        $hasStudents = Student::where('department_id', $department->id)
            ->orWhere('sc_admin_department_id', $department->id)
            ->exists();

        if ($hasStudents) {
            throw new DepartmentHasStudentsException;
        }

        $logoPath = $department->logo_path;

        $department->delete();

        if ($logoPath) {
            try {
                Storage::disk('public')->delete($logoPath);
            } catch (Throwable $e) {
                // Non-fatal, same reasoning as UpdateDepartmentLogo: the
                // department record is already gone, so a leftover logo
                // file is just a cleanup task.
                Log::warning('Failed to delete logo for a removed department.', [
                    'department_id' => $department->id,
                    'path' => $logoPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
