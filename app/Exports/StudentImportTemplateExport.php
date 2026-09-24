<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Spec §4.2: "Provide a downloadable template (columns matching required
 * fields)". Columns match StoreStudentRequest's field set exactly, minus
 * student_number's uniqueness rule (obviously not checkable in a blank
 * template) and plus department_code instead of department_id, since a
 * spreadsheet author has a department code on hand, not our internal id.
 */
class StudentImportTemplateExport implements FromArray, WithHeadings
{
    public function headings(): array
    {
        return [
            'student_number', 'last_name', 'first_name', 'middle_name',
            'suffix', 'department_code', 'year_level', 'section',
        ];
    }

    public function array(): array
    {
        // One filled-in example row so the expected format (e.g. that
        // suffix/section can be left blank) is obvious without needing a
        // separate instructions sheet.
        return [
            ['2023000001', 'Dela Cruz', 'Juan', 'Santos', '', 'BSIT', '1', 'A'],
        ];
    }
}
