<?php

namespace App\Http\Requests\Penalties;

use App\Models\AttendancePenalty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPenaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AttendancePenalty::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'status' => ['nullable', 'string', Rule::in(['all', 'active', 'reversed'])],
            // Matches against student_number, last_name, or first_name —
            // same fields StudentController's own search filter checks.
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
