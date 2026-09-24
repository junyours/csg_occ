<?php

namespace App\Application\Actions\Students;

use App\Domain\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class AssignStudentRole
{
    /**
     * Spec §4.4: promotes — or demotes, back to `student` — a student's
     * role in one step, setting exactly the scope field the new role
     * needs and clearing the other (a student only ever holds one kind of
     * scope at a time), then writes the role_assignments audit row in the
     * same transaction so the two can't drift apart.
     *
     * $departmentId is only persisted when $newRole is ScAdmin; $eventId
     * only when $newRole is Officer (and is optional even then, per spec
     * §4.4). Values that don't apply to the new role are dropped, not
     * just left unset, so a promote → demote → re-promote cycle can't
     * leave a stale scope behind from an earlier role.
     */
    public function __invoke(
        Student $student,
        Role $newRole,
        ?int $departmentId,
        ?int $eventId,
        Student $changedBy,
    ): Student {
        return DB::transaction(function () use ($student, $newRole, $departmentId, $eventId, $changedBy) {
            $oldRole = $student->role;

            $student->update([
                'role' => $newRole,
                'sc_admin_department_id' => $newRole === Role::ScAdmin ? $departmentId : null,
                'officer_event_id' => $newRole === Role::Officer ? $eventId : null,
            ]);

            RoleAssignment::create([
                'student_id' => $student->id,
                'old_role' => $oldRole->value,
                'new_role' => $newRole->value,
                'changed_by' => $changedBy->id,
            ]);

            return $student->fresh();
        });
    }
}
