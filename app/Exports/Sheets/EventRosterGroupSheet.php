<?php

namespace App\Exports\Sheets;

use App\Domain\Support\RosterReportColumnGrouper;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * One sheet per department/year-level/section group (spec's own
 * example: "BSIT, 1st year, 1st section -> BSIT 1A"): one row per
 * student — already sorted a-z by last name by BuildEventRosterReport
 * — one column per session, and a trailing penalty-total column.
 *
 * headings()/array() stay exactly the row-1-is-headings,
 * row-2-onward-is-students shape the controller tests assert on
 * (EventRosterReportControllerTest reads $sheets[0]->array()[0][0] as
 * the first student's number). All of the "good design" — a banner
 * title, a colour-coded status legend, banding, borders, a frozen
 * header — is layered on top afterwards in registerEvents(), which
 * edits the already-written worksheet rather than changing what
 * headings()/array() report.
 */
final class EventRosterGroupSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    private const BANNER_FILL = 'FFFFFF'; // white — plain banner instead of the old solid dark emerald
    private const BANNER_TEXT = '000000'; // black
    private const SUBTITLE_FILL = 'ECFDF5'; // emerald-50
    private const SUBTITLE_TEXT = '064E3B'; // emerald-900
    private const TOTAL_FILL = 'F1F5F9'; // slate-100
    private const BORDER_COLOR = 'D1D5DB'; // gray-300
    private const BAND_FILL = 'F9FAFB'; // gray-50

    // Student No./Last Name/First Name — Excel's classic Accent 1 blue,
    // used to be the same dark slate as everything else.
    private const STATIC_FILL = '4F81BD';
    private const STATIC_TEXT = 'FFFFFF';

    // Penalty Total — Excel's classic Accent 5 teal, kept distinct from
    // the blue static columns so the running total still stands out.
    private const PENALTY_FILL = '4BACC6';
    private const PENALTY_TEXT = 'FFFFFF';

    /**
     * One flat colour per day, cycling if there are more than three —
     * Excel's own built-in Good/Neutral/Bad cell styles, reused here so
     * each day's whole column block (Day/Window/Time In-Out rows all the
     * way down) reads as one coloured group at a glance instead of the
     * old single dark header.
     */
    private const DAY_PALETTE = [
        ['C6EFCE', '006100'], // Excel "Good" — green
        ['FFEB9C', '9C6500'], // Excel "Neutral" — yellow
        ['FFC7CE', '9C0006'], // Excel "Bad" — red/pink
    ];

    /** status value => [fill, text color] */
    private const STATUS_COLORS = [
        'present' => ['D1FAE5', '065F46'],  // emerald-100 / emerald-800
        'late' => ['FEF3C7', '92400E'],     // amber-100 / amber-800
        'absent' => ['FEE2E2', '991B1B'],   // red-100 / red-800
        'excluded' => ['F3F4F6', '374151'], // gray-100 / gray-700
        'pending' => ['F9FAFB', '6B7280'],  // gray-50 / gray-500
        // Indigo, matching .status-reversed in the roster PDF blade so the
        // same report reads the same in both formats. Deliberately not red:
        // a reversed penalty is forgiven, and colouring it like Absent is
        // exactly the confusion this status exists to remove.
        'reversed' => ['E0E7FF', '3730A3'], // indigo-100 / indigo-800
    ];

    public function __construct(
        private readonly array $group,
        private readonly array $sessions,
        private readonly string $eventName = '',
        /**
         * Invoked once this sheet has been fully built and styled —
         * ProcessRosterReportGeneration uses this the same way
         * BuildEventRosterReport's onGroupBuilt is used on the data
         * side, to advance a real, per-sheet progress counter for a
         * full-school export instead of a fake timer. Null everywhere
         * else, including every existing caller/test — a no-op.
         */
        private readonly mixed $onSheetWritten = null,
    ) {}

    public function title(): string
    {
        $title = trim(sprintf('%s%s %s%s', $this->group['department_code'], $this->group['major'] ? '-'.$this->group['major'] : '', $this->group['year_level'], $this->group['section']));

        // Excel sheet names are capped at 31 characters — long department
        // names/codes are the only realistic way to hit that here.
        return substr($title !== '' ? $title : 'Group', 0, 31);
    }

    /**
     * This is only the bottom-most header row now — the check label
     * ("Time In"/"Time Out") per session column. The Day/Window rows
     * above it (e.g. "Day 1" spanning "Morning"+"Afternoon", each of
     * those spanning their Time In/Time Out columns) are written
     * directly onto the worksheet in registerEvents(), since
     * WithHeadings only ever supports a single header row — a day with
     * only a Morning session never gets empty Afternoon/Evening
     * columns, matching the PDF's dynamic header (see
     * RosterReportColumnGrouper).
     */
    public function headings(): array
    {
        $headings = ['Student No.', 'Last Name', 'First Name'];

        foreach ($this->sessions as $session) {
            $headings[] = $session['check_type'] === 'time_out' ? 'Time Out' : 'Time In';
        }

        $headings[] = 'Penalty Total';

        return $headings;
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->group['students'] as $student) {
            $row = [$student['student_number'], $student['last_name'], $student['first_name']];

            foreach ($this->sessions as $session) {
                $row[] = $this->formatCell($student['sessions'][$session['id']] ?? null);
            }

            $row[] = number_format($student['penalty_total'], 2);

            $rows[] = $row;
        }

        $rows[] = array_merge(
            ['', '', 'Group Total'],
            array_fill(0, count($this->sessions), ''),
            [number_format($this->group['group_penalty_total'], 2)],
        );

        return $rows;
    }

    private function formatCell(?string $status): string
    {
        return $status ? ucfirst($status) : 'Pending';
    }

    /**
     * Everything below is presentation only — it never touches the
     * data headings()/array() already handed the writer, it just
     * reaches into the worksheet afterward to dress it up:
     *
     *   Row 1  merged banner  — event name
     *   Row 2  merged banner  — group name ("BSIT — 1st Year — Section A")
     *   Row 3  meta line      — generated-on date + student count
     *   Row 4  Day header     — only present when the event has any
     *          (optional)       sessions ("Day 1", merged across its windows)
     *   Row 5  Window header  — only present alongside the Day header
     *          (optional)       ("Morning"/"Afternoon"/"Evening", merged
     *                           across its Time In/Time Out columns)
     *   Row 6  Check header    — the original headings(), restyled
     *   (or 4 if no day/window headers apply)
     *   Row 7+ student rows   — the original array() rows, restyled
     *          + a colour fill per attendance status cell
     *   Last   group total    — restyled, bold, top border
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $studentCount = count($this->group['students']);
                $lastColumnIndex = 3 + count($this->sessions) + 1;
                $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

                $dayColumns = RosterReportColumnGrouper::groupByDayAndWindow($this->sessions);
                $hasDayColumns = count($dayColumns) > 0;
                $extraHeaderRows = $hasDayColumns ? 2 : 0;

                // 3 banner rows (+ 2 more when there's a Day/Window header
                // to draw) pushed above the header/data the package
                // already wrote (originally row 1 = headings, row 2+ = data).
                $sheet->insertNewRowBefore(1, 3 + $extraHeaderRows);

                $headerRow = 4 + $extraHeaderRows;
                $firstDataRow = $headerRow + 1;
                $totalRow = $firstDataRow + $studentCount; // the "Group Total" row array() appends
                $lastRow = $totalRow;

                $this->styleBanner($sheet, $lastColumn);

                if ($hasDayColumns) {
                    $dayRow = 4;
                    $windowRow = 5;
                    $this->styleDayWindowHeaders($sheet, $dayColumns, $dayRow, $windowRow, $headerRow, $lastColumn);
                }

                $this->styleHeaderRow($sheet, $headerRow, $lastColumn);
                $this->styleDataRows($sheet, $firstDataRow, $studentCount, $lastColumn);
                $this->styleTotalRow($sheet, $totalRow, $lastColumn);

                $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")
                    ->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setARGB('FF'.self::BORDER_COLOR);

                $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$headerRow}");
                $sheet->freezePane('D'.$firstDataRow);
                $sheet->getRowDimension($headerRow)->setRowHeight(30);
                $sheet->getRowDimension(1)->setRowHeight(22);
                $sheet->getRowDimension(2)->setRowHeight(18);

                if ($this->onSheetWritten !== null) {
                    ($this->onSheetWritten)();
                }
            },
        ];
    }

    /**
     * Draws the two extra header rows above the check-level heading row:
     * "Day N" merged across however many check columns its windows span,
     * and each window's name ("Morning"/"Afternoon"/"Evening") merged
     * across its own Time In/Time Out columns — a day with only a
     * Morning session never produces empty Afternoon/Evening cells,
     * mirroring the PDF's dynamic header exactly (same
     * RosterReportColumnGrouper input). The four static columns
     * (Student No./Last/First/Penalty Total) span vertically across
     * all three header rows via a merge, the sheet equivalent of the
     * PDF's rowspan="3".
     */
    private function styleDayWindowHeaders($sheet, array $dayColumns, int $dayRow, int $windowRow, int $checkRow, string $lastColumn): void
    {
        // headings() wrote its text into what is now the bottom row of
        // this 3-row block (checkRow) — merging cells only keeps the
        // *top-left* cell's value visible, so each static column's label
        // has to move up to dayRow before merging, or it'd disappear
        // behind the merge. Student No./Last/First get the blue static
        // colour; Penalty Total gets its own teal so it still stands out.
        foreach (['A', 'B', 'C'] as $staticColumn) {
            $sheet->setCellValue("{$staticColumn}{$dayRow}", $sheet->getCell("{$staticColumn}{$checkRow}")->getValue());
            $sheet->setCellValue("{$staticColumn}{$checkRow}", null);
            $sheet->mergeCells("{$staticColumn}{$dayRow}:{$staticColumn}{$checkRow}");
            $sheet->getStyle("{$staticColumn}{$dayRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::STATIC_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::STATIC_FILL]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        }

        $sheet->setCellValue("{$lastColumn}{$dayRow}", $sheet->getCell("{$lastColumn}{$checkRow}")->getValue());
        $sheet->setCellValue("{$lastColumn}{$checkRow}", null);
        $sheet->mergeCells("{$lastColumn}{$dayRow}:{$lastColumn}{$checkRow}");
        $sheet->getStyle("{$lastColumn}{$dayRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::PENALTY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::PENALTY_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $col = 4; // column D — the first session column, after Student No./Last/First
        $paletteSize = count(self::DAY_PALETTE);

        foreach ($dayColumns as $dayIndex => $day) {
            [$dayFill, $dayText] = self::DAY_PALETTE[$dayIndex % $paletteSize];

            $dayStart = $col;
            $dayEnd = $col + $day['span'] - 1;
            $dayStartLetter = Coordinate::stringFromColumnIndex($dayStart);
            $dayEndLetter = Coordinate::stringFromColumnIndex($dayEnd);

            if ($dayEnd > $dayStart) {
                $sheet->mergeCells("{$dayStartLetter}{$dayRow}:{$dayEndLetter}{$dayRow}");
            }
            $sheet->setCellValue("{$dayStartLetter}{$dayRow}", 'Day '.$day['day_number']);

            // The whole day block — Day row, Window row, and the actual
            // Time In/Out row underneath it — shares one flat colour, so
            // the block reads as a single coloured group top to bottom
            // rather than stopping above the real header.
            $sheet->getStyle("{$dayStartLetter}{$dayRow}:{$dayEndLetter}{$checkRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF'.$dayText]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$dayFill]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            foreach ($day['windows'] as $window) {
                $winStart = $col;
                $winEnd = $col + $window['span'] - 1;
                $winStartLetter = Coordinate::stringFromColumnIndex($winStart);
                $winEndLetter = Coordinate::stringFromColumnIndex($winEnd);

                if ($winEnd > $winStart) {
                    $sheet->mergeCells("{$winStartLetter}{$windowRow}:{$winEndLetter}{$windowRow}");
                }
                $sheet->setCellValue("{$winStartLetter}{$windowRow}", ucfirst($window['window_type']));
                $sheet->getStyle("{$winStartLetter}{$windowRow}:{$winEndLetter}{$checkRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.$dayText]],
                ]);
                $sheet->getStyle("{$winStartLetter}{$checkRow}:{$winEndLetter}{$checkRow}")
                    ->getAlignment()->setWrapText(true);

                $col += $window['span'];
            }
        }

        $sheet->getRowDimension($dayRow)->setRowHeight(22);
        $sheet->getRowDimension($windowRow)->setRowHeight(22);
    }

    private function styleBanner($sheet, string $lastColumn): void
    {
        $groupLabel = trim(sprintf(
            '%s — Year %s — Section %s',
            trim($this->group['department_code'].($this->group['major'] ? ' '.$this->group['major'] : '')),
            $this->group['year_level'] ?: '—',
            $this->group['section'] ?: '—',
        ));

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', trim(($this->eventName !== '' ? $this->eventName.' — ' : '').'Roster Report'));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF'.self::BANNER_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BANNER_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', $groupLabel);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF'.self::SUBTITLE_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::SUBTITLE_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->setCellValue('A3', sprintf(
            'Generated %s • %d student%s • sorted A–Z by last name',
            now()->format('M j, Y g:i A'),
            count($this->group['students']),
            count($this->group['students']) === 1 ? '' : 's',
        ));
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF6B7280']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    /**
     * Styles just the static columns (Student No./Last/First, Penalty
     * Total) on the check-level row. Session columns are styled by
     * styleDayWindowHeaders() with their day's colour instead — but that
     * only runs when the event actually has sessions, so this is also
     * the only place those static columns get coloured when there are
     * none (hasDayColumns false, lastColumn sits right after C).
     */
    private function styleHeaderRow($sheet, int $headerRow, string $lastColumn): void
    {
        $sheet->getStyle("A{$headerRow}:C{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::STATIC_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::STATIC_FILL]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle("{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::PENALTY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::PENALTY_FILL]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    private function styleDataRows($sheet, int $firstDataRow, int $studentCount, string $lastColumn): void
    {
        for ($i = 0; $i < $studentCount; $i++) {
            $row = $firstDataRow + $i;

            $sheet->getStyle("A{$row}:C{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

            // Zebra banding on the name columns only — the status columns
            // get their own colour per cell below, banding them too would
            // just muddy the status colour.
            if ($i % 2 === 1) {
                $sheet->getStyle("A{$row}:C{$row}")->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::BAND_FILL]],
                ]);
            }

            $status = $this->group['students'][$i]['sessions'] ?? [];
            foreach ($this->sessions as $sessionIndex => $session) {
                $columnLetter = Coordinate::stringFromColumnIndex(4 + $sessionIndex);
                $cellValue = strtolower((string) ($status[$session['id']] ?? 'pending'));
                [$fill, $text] = self::STATUS_COLORS[$cellValue] ?? self::STATUS_COLORS['pending'];

                $sheet->getStyle("{$columnLetter}{$row}")->applyFromArray([
                    'font' => ['color' => ['argb' => 'FF'.$text], 'bold' => $cellValue === 'present'],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$fill]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
            }

            $penaltyColumn = $lastColumn;
            $sheet->getStyle("{$penaltyColumn}{$row}")->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
                'font' => ['bold' => (float) $this->group['students'][$i]['penalty_total'] > 0],
            ]);
        }
    }

    private function styleTotalRow($sheet, int $totalRow, string $lastColumn): void
    {
        $sheet->getStyle("A{$totalRow}:{$lastColumn}{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::TOTAL_FILL]],
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF'.self::STATIC_FILL]]],
        ]);
        $sheet->getStyle("{$lastColumn}{$totalRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }
}
