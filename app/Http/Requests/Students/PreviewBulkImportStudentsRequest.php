<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class PreviewBulkImportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same ability as the real import — previewing costs nothing extra
        // permission-wise, it's the exact same file an authorized actor
        // could otherwise commit directly.
        return $this->user()?->can('bulkImport', Student::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:250'],
            'files.*' => ['file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }
}
