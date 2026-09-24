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
 * The master report's sheet — same one-sheet-per-department/year-level
 * /section shape as EventRosterGroupSheet, except the sessions column
 * block spans every selected event side by side (Event -> Day ->
 * Window -> Check, see the attached mock), not just one event's days.
 * Kept as its own class rather than reusing EventRosterGroupSheet
 * because the header now needs a fourth row (the event name) on top of
 * the existing day/window/check rows.
 */
final class MasterRosterGroupSheet implements FromArray, ShouldAutoSize, WithEvents, WithHeadings, WithTitle
{
    private const BANNER_FILL = 'FFFFFF'; // white — plain banner, matching the single-event roster sheet
    private const BANNER_TEXT = '000000'; // black
    private const SUBTITLE_FILL = 'ECFDF5'; // emerald-50
    private const SUBTITLE_TEXT = '064E3B'; // emerald-900
    private const TOTAL_FILL = 'F1F5F9'; // slate-100
    private const BORDER_COLOR = 'D1D5DB'; // gray-300
    private const BAND_FILL = 'F9FAFB'; // gray-50

    // Student No./Last Name/First Name — Excel's classic Accent 1 blue,
    // same as EventRosterGroupSheet.
    private const STATIC_FILL = '4F81BD';
    private const STATIC_TEXT = 'FFFFFF';

    // Penalty Total — Excel's classic Accent 5 teal.
    private const PENALTY_FILL = '4BACC6';
    private const PENALTY_TEXT = 'FFFFFF';

    /**
     * One flat colour per event, cycling if there are more than three —
     * Excel's own built-in Good/Neutral/Bad cell styles, same trio
     * EventRosterGroupSheet cycles per day. Here the event is the
     * outermost grouping, so it takes the place day did there: a whole
     * event's Event/Day/Window/Check header rows share one flat colour
     * top to bottom instead of the old darker-to-lighter shading.
     */
    private const EVENT_PALETTE = [
        ['C6EFCE', '006100'], // Excel "Good" — green
        ['FFEB9C', '9C6500'], // Excel "Neutral" — yellow
        ['FFC7CE', '9C0006'], // Excel "Bad" — red/pink
    ];

    /** status value => [fill, text color] — already candy-pastel, unchanged */
    private const STATUS_COLORS = [
        'present' => ['D1FAE5', '065F46'],
        'late' => ['FEF3C7', '92400E'],
        'absent' => ['FEE2E2', '991B1B'],
        'excluded' => ['F3F4F6', '374151'],
        'pending' => ['F9FAFB', '6B7280'],
        // See EventRosterGroupSheet — same indigo as the PDF's
        // .status-reversed, kept clear of Absent's red on purpose.
        'reversed' => ['E0E7FF', '3730A3'],
    ];

    public function __construct(
        private readonly array $group,
        private readonly array $sessions,
        private readonly array $events,
        private readonly mixed $onSheetWritten = null,
    ) {}

    public function title(): string
    {
        $title = trim(sprintf('%s%s %s%s', $this->group['department_code'], $this->group['major'] ? '-'.$this->group['major'] : '', $this->group['year_level'], $this->group['section']));

        return substr($title !== '' ? $title : 'Group', 0, 31);
    }

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
     * Same layering as EventRosterGroupSheet's registerEvents, with one
     * more header row inserted for the Event grouping:
     *
     *   Row 1  merged banner  — "Master Roster Report"
     *   Row 2  merged banner  — group name
     *   Row 3  meta line      — generated-on date + student count
     *   Row 4  Event header   — only present when there are session
     *          (optional)       columns at all ("Event 1", merged
     *                           across its days)
     *   Row 5  Day header     — merged across its windows
     *          (optional)
     *   Row 6  Window header  — merged across its Time In/Time Out columns
     *          (optional)
     *   Row 7  Check header    — the original headings(), restyled
     *   (or 4 if no session columns apply)
     *   Row 8+ student rows   — restyled + a colour fill per status cell
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

                $eventColumns = RosterReportColumnGrouper::groupByEventDayAndWindow($this->sessions);
                $hasSessionColumns = count($eventColumns) > 0;
                $extraHeaderRows = $hasSessionColumns ? 3 : 0;

                $sheet->insertNewRowBefore(1, 3 + $extraHeaderRows);

                $headerRow = 4 + $extraHeaderRows;
                $firstDataRow = $headerRow + 1;
                $totalRow = $firstDataRow + $studentCount;
                $lastRow = $totalRow;

                $this->styleBanner($sheet, $lastColumn);
                $this->styleHeaderRow($sheet, $headerRow, $lastColumn);

                if ($hasSessionColumns) {
                    $eventRow = 4;
                    $dayRow = 5;
                    $windowRow = 6;
                    $this->styleEventDayWindowHeaders($sheet, $eventColumns, $eventRow, $dayRow, $windowRow, $headerRow, $lastColumn);
                }
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
     * Draws three extra header rows above the check-level heading row:
     * the event name merged across every one of its day columns, each
     * day merged across its windows, and each window merged across its
     * Time In/Time Out columns — the same dynamic "only what's actually
     * configured" rule as the single-event PDF/sheet, just with the
     * event grouping added on top.
     */
    private function styleEventDayWindowHeaders($sheet, array $eventColumns, int $eventRow, int $dayRow, int $windowRow, int $checkRow, string $lastColumn): void
    {
        foreach (['A', 'B', 'C'] as $staticColumn) {
            $sheet->setCellValue("{$staticColumn}{$eventRow}", $sheet->getCell("{$staticColumn}{$checkRow}")->getValue());
            $sheet->setCellValue("{$staticColumn}{$checkRow}", null);
            $sheet->mergeCells("{$staticColumn}{$eventRow}:{$staticColumn}{$checkRow}");
            $sheet->getStyle("{$staticColumn}{$eventRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::STATIC_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::STATIC_FILL]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        }

        $sheet->setCellValue("{$lastColumn}{$eventRow}", $sheet->getCell("{$lastColumn}{$checkRow}")->getValue());
        $sheet->setCellValue("{$lastColumn}{$checkRow}", null);
        $sheet->mergeCells("{$lastColumn}{$eventRow}:{$lastColumn}{$checkRow}");
        $sheet->getStyle("{$lastColumn}{$eventRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.self::PENALTY_TEXT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.self::PENALTY_FILL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $col = 4; // column D — the first session column, after Student No./Last/First
        $paletteSize = count(self::EVENT_PALETTE);

        foreach ($eventColumns as $eventIndex => $eventColumn) {
            [$eventFill, $text] = self::EVENT_PALETTE[$eventIndex % $paletteSize];

            $eventStart = $col;
            $eventEnd = $col + $eventColumn['span'] - 1;
            $eventStartLetter = Coordinate::stringFromColumnIndex($eventStart);
            $eventEndLetter = Coordinate::stringFromColumnIndex($eventEnd);

            if ($eventEnd > $eventStart) {
                $sheet->mergeCells("{$eventStartLetter}{$eventRow}:{$eventEndLetter}{$eventRow}");
            }
            $sheet->setCellValue("{$eventStartLetter}{$eventRow}", $eventColumn['event_name']);

            // The whole event block — Event, Day, Window, and the actual
            // Time In/Out row underneath it — shares one flat colour, so
            // the block reads as a single coloured group top to bottom
            // instead of shading darker-to-lighter down the header.
            $sheet->getStyle("{$eventStartLetter}{$eventRow}:{$eventEndLetter}{$checkRow}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FF'.$text]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF'.$eventFill]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            foreach ($eventColumn['days'] as $day) {
                $dayStart = $col;
                $dayEnd = $col + $day['span'] - 1;
                $dayStartLetter = Coordinate::stringFromColumnIndex($dayStart);
                $dayEndLetter = Coordinate::stringFromColumnIndex($dayEnd);

                if ($dayEnd > $dayStart) {
                    $sheet->mergeCells("{$dayStartLetter}{$dayRow}:{$dayEndLetter}{$dayRow}");
                }
                $sheet->setCellValue("{$dayStartLetter}{$dayRow}", 'Day '.$day['day_number']);
                $sheet->getStyle("{$dayStartLetter}{$dayRow}:{$dayEndLetter}{$dayRow}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.$text]],
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
                        'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FF'.$text]],
                    ]);
                    $sheet->getStyle("{$winStartLetter}{$checkRow}:{$winEndLetter}{$checkRow}")
                        ->getAlignment()->setWrapText(true);

                    $col += $window['span'];
                }
            }
        }

        $sheet->getRowDimension($eventRow)->setRowHeight(24);
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

        $eventNames = implode(', ', array_column($this->events, 'name'));

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', 'Master Roster Report — '.$eventNames);
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
            'Generated %s • %d student%s • sorted A–Z by last name • %d event%s combined',
            now()->format('M j, Y g:i A'),
            count($this->group['students']),
            count($this->group['students']) === 1 ? '' : 's',
            count($this->events),
            count($this->events) === 1 ? '' : 's',
        ));
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF6B7280']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    /**
     * Styles just the static columns (Student No./Last/First, Penalty
     * Total) on the check-level row. Session columns are styled by
     * styleEventDayWindowHeaders() with their event's colour instead —
     * but that only runs when there are session columns at all, so this
     * is also the only place those static columns get coloured when
     * there are none (lastColumn sits right after C).
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
