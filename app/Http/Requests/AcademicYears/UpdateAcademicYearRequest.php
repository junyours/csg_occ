<?php

namespace App\Http\Requests\AcademicYears;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', AcademicYear::class) ?? false;
    }

    public function rules(): array
    {
        // Route model binding resolves before rules() runs, so
        // $this->route('academicYear') is a real AcademicYear here.
        $academicYear = $this->route('academicYear');

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:50',
                Rule::unique('academic_years', 'name')->ignore($academicYear?->id),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
