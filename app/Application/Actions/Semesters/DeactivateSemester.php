<?php

namespace App\Application\Actions\Semesters;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\Role;
use App\Domain\Exceptions\EventsStillOngoingException;
use App\Models\EventModel;
use App\Models\Semester;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class DeactivateSemester
{
    /**
     * The rollover sweep lives here only. On a real active -> inactive
     * transition, in one transaction: refuse while events are ongoing; demote
     * CSG/SC Admin/Officer to student (scope cleared, audit row each); set
     * every non-System-Admin account inactive; revoke their tokens.
     */
    public function __invoke(Semester $semester, Student $actor): Semester
    {
        return DB::transaction(function () use ($semester, $actor) {
            $locked = Semester::whereKey($semester->id)->lockForUpdate()->first();

            if (! $locked->is_active) {
                return $locked->fresh();
            }

            $ongoing = EventModel::where('status', EventStatus::Ongoing)->orderBy('id')->pluck('name')->all();

            if ($ongoing !== []) {
                throw new EventsStillOngoingException($ongoing);
            }

            $locked->update(['is_active' => false]);

            $this->sweep($actor);

            return $locked->fresh();
        });
    }

    private function sweep(Student $actor): void
    {
        $staffRoles = [Role::CsgAdmin->value, Role::ScAdmin->value, Role::Officer->value];
        $now = now();

        $audit = Student::whereIn('role', $staffRoles)->get(['id', 'role'])
            ->map(fn (Student $s) => [
                'student_id' => $s->id,
                'old_role' => $s->role->value,
                'new_role' => Role::Student->value,
                'changed_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        foreach (array_chunk($audit, 500) as $chunk) {
            DB::table('role_assignments')->insert($chunk);
        }

        Student::whereIn('role', $staffRoles)->update([
            'role' => Role::Student->value,
            'sc_admin_department_id' => null,
            'officer_event_id' => null,
        ]);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new Student)->getMorphClass())
            ->whereIn('tokenable_id', Student::query()->where('role', '!=', Role::SystemAdmin->value)->select('id'))
            ->delete();

        Student::where('role', '!=', Role::SystemAdmin->value)->update(['status' => Student::STATUS_INACTIVE]);
    }
}
