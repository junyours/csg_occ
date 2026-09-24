<?php

namespace App\Http\Requests\Semesters;

use App\Domain\Enums\Semester as SemesterEnum;
use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Semester::class) ?? false;
    }

    public function rules(): array
    {
        // The parent {academicYear} route-model is resolved before this
        // request's rules() runs, so it's safe to read here for the
        // per-academic-year uniqueness check.
        $academicYear = $this->route('academicYear');

        return [
            'name' => [
                'required',
                Rule::enum(SemesterEnum::class),
                Rule::unique('semesters', 'name')->where('academic_year_id', $academicYear?->id),
            ],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // Optional — creating one as active is a shortcut for
            // create-then-activate, not a separate ability.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
