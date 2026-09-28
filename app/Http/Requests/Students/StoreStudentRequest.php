<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Casts a missing department_id to 0 rather than short-circuiting:
        // CSG/System Admin don't need a real id to pass (their branch of
        // the policy ignores it), so a missing field still reaches
        // validation and comes back as a 422, not a misleading 403.
        return $this->user()?->can('create', [Student::class, (int) $this->input('department_id')]) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'student_number' => trim((string) $this->input('student_number')),
            'last_name' => trim((string) $this->input('last_name')),
            'first_name' => trim((string) $this->input('first_name')),
        ]);
    }

    public function rules(): array
    {
        return [
            // No `unique`: an existing number is handled in the controller (update mode).
            'student_number' => ['required', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['required', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            // Only some courses (BSBA's FM/MM, BSED's ENG, ...) have a
            // sub-major — nullable rather than tied to department, since
            // that's a per-course fact the section filenames encode, not
            // something the schema enforces.
            'major' => ['nullable', 'string', 'max:50'],
            'year_level' => ['required', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'date_enrolled' => ['nullable', 'date'],
            'update_existing' => ['sometimes', 'boolean'],
        ];
    }
}
