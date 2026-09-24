<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class ShowBulkImportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulkImport', Student::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
