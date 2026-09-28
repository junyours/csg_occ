<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class ResetStudentPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('student');

        return $target instanceof Student
            && ($this->user()?->can('resetPassword', $target) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
