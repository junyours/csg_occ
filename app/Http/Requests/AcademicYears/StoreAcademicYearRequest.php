<?php

namespace App\Http\Requests\AcademicYears;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicYear::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'unique:academic_years,name'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Optional — creating one as active is a shortcut for
            // create-then-activate, not a separate ability.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
