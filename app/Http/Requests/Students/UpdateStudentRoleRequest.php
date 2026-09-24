<?php

namespace App\Http\Requests\Students;

use App\Domain\Enums\Role;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('student');
        $role = $this->targetRole();

        // Route model binding resolves (or 404s) before authorize() runs,
        // so $target is always a real Student here — the only unknown is
        // whether "role" is a valid enum value. Fall through to
        // validation (422) rather than guessing a 403 for a bad role.
        if (! $target instanceof Student || ! $role) {
            return true;
        }

        return $this->user()?->can('assignRole', [$target, $role]) ?? false;
    }

    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(Role::class)],
            // Required only for sc_admin (spec §4.4); event_id has no
            // matching required_if since an officer's event is optional.
            'department_id' => [
                Rule::requiredIf(fn () => $this->targetRole() === Role::ScAdmin),
                'nullable', 'integer', 'exists:departments,id',
            ],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
        ];
    }

    private function targetRole(): ?Role
    {
        return Role::tryFrom((string) $this->input('role'));
    }
}
