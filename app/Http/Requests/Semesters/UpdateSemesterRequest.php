<?php

namespace App\Http\Requests\Semesters;

use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Semester::class) ?? false;
    }

    public function rules(): array
    {
        // name and academic_year_id are immutable after creation (see
        // UpdateSemester) so only the dates are validated here.
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
