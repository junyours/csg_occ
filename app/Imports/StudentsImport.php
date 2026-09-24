<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Deliberately dumb: this class's only job is turning the uploaded file
 * into a Collection of rows keyed by (slugified) header name. All the
 * real validation, department resolution, and student creation lives in
 * BulkImportStudents so it stays unit-testable without touching a real
 * spreadsheet file.
 */
class StudentsImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
