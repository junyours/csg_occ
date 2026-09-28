<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('student');

        return $target instanceof Student && ($this->user()?->can('updateStatus', $target) ?? false);
    }

    public function rules(): array
    {
        return ['status' => ['required', 'string', Rule::in([Student::STATUS_ACTIVE, Student::STATUS_INACTIVE])]];
    }
}
