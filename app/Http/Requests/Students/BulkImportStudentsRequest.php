<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class BulkImportStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulkImport', Student::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // One file per section now, so a batch upload can cover an
            // entire department in one go — 250 is comfortably above any
            // real course's section count while still being a sane cap.
            'files' => ['required', 'array', 'min:1', 'max:250'],
            // 5MB is generous for a spreadsheet of plain-text student rows
            // and cheap to reject up front, well before the MAX_ROWS_PER_FILE
            // check in BulkImportStudents even has to parse the file.
            'files.*' => ['file', 'mimes:xlsx,xls,csv', 'max:5120'],
        ];
    }
}
