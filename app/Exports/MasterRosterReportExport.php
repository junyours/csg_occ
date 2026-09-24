<?php

namespace App\Exports;

use App\Exports\Sheets\MasterRosterGroupSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The Excel export of the report built by BuildMasterRosterReport —
 * one sheet per department/year-level/section group, same as
 * EventRosterReportExport, except every sheet's session columns span
 * every selected event side by side.
 */
final class MasterRosterReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $report,
        private readonly mixed $onSheetWritten = null,
    ) {}

    public function sheets(): array
    {
        if ($this->report['groups'] === []) {
            return [new MasterRosterGroupSheet(
                [
                    'department_code' => 'No students found',
                    'year_level' => '', 'section' => '',
                    'students' => [], 'group_penalty_total' => 0.0,
                ],
                $this->report['sessions'],
                $this->report['events'],
                $this->onSheetWritten,
            )];
        }

        return array_map(
            fn (array $group) => new MasterRosterGroupSheet(
                $group,
                $this->report['sessions'],
                $this->report['events'],
                $this->onSheetWritten,
            ),
            $this->report['groups'],
        );
    }
}
