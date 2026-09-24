<?php

namespace App\Http\Requests\Exclusions;

use App\Models\Exclusion;
use Illuminate\Foundation\Http\FormRequest;

class PreviewBulkExclusionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same ability as a single add — previewing costs nothing extra
        // permission-wise (mirrors PreviewBulkImportStudentsRequest).
        return $this->user()?->can('create', Exclusion::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }
}
