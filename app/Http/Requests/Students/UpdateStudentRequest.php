<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('student');

        if (! $target instanceof Student || ! $this->user()?->can('update', $target)) {
            return false;
        }

        // Moving a student to another department needs create-rights there.
        if ($this->filled('department_id')) {
            return $this->user()->can('create', [Student::class, (int) $this->input('department_id')]);
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'department_id' => ['sometimes', 'required', 'integer', 'exists:departments,id'],
            'major' => ['sometimes', 'nullable', 'string', 'max:50'],
            'year_level' => ['sometimes', 'required', 'string', 'max:20'],
            'section' => ['sometimes', 'nullable', 'string', 'max:50'],
            'date_enrolled' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
