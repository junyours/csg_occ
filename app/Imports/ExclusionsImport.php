<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Deliberately dumb, same pattern as App\Imports\StudentsImport: this
 * class's only job is turning the uploaded bulk-exclusion CSV
 * (student-exclusion-feature-plan.md §5's format — student_id, scope,
 * day, window) into a Collection of rows keyed by slugified header name.
 * All real validation and exclusion creation lives in
 * BulkCreateExclusions so it stays unit-testable without a real file.
 */
class ExclusionsImport implements ToCollection, WithHeadingRow
{
    public Collection $rows;

    public function collection(Collection $rows): void
    {
        $this->rows = $rows;
    }
}
