<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Student::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // An SC Admin may further narrow within their own department,
            // but may not widen past it — that boundary is enforced in the
            // controller (query scoping), not here.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'role' => ['nullable', 'string', Rule::in(['system_admin', 'csg_admin', 'sc_admin', 'officer', 'student'])],
            'major' => ['nullable', 'string', 'max:50'],
            'year_level' => ['nullable', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            // Matches against student_number, last_name, or first_name.
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }
}
