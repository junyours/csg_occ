<?php

namespace App\Http\Requests\Exclusions;

use App\Models\Exclusion;
use Illuminate\Foundation\Http\FormRequest;

class BulkExclusionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Exclusion::class) ?? false;
    }

    /**
     * student-exclusion-feature-plan.md §5 step 3: one reason field
     * applies to the entire batch — required here, same as the single
     * StoreExclusionRequest.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
