<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Master Roster Report</title>
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #111; }
    .banner { background: #fff; color: #000; padding: 8px 10px; border-radius: 3px 3px 0 0; }
    .banner h1 { font-size: 15px; margin: 0; font-weight: bold; }
    .subtitle { background: #ecfdf5; color: #064e3b; padding: 5px 10px; font-size: 11px; font-weight: bold; }
    .meta { color: #6b7280; font-style: italic; font-size: 8.5px; padding: 3px 10px 8px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th, td { border: 1px solid #d1d5db; padding: 3px 6px; text-align: left; }
    th { font-weight: bold; }
    /* Student No./Last/First/# — Excel's classic Accent 1 blue */
    th.static-header { background: #4F81BD; color: #fff; }
    /* Penalty Total — Excel's classic Accent 5 teal, kept distinct so the running total still stands out */
    th.penalty-header { background: #4BACC6; color: #fff; }
    th.event-header, th.day-header, th.window-header, th.check-header { text-align: center; }
    /* One flat colour per event, cycling — Excel's own built-in
       Good/Neutral/Bad cell styles, same trio the single-event roster
       PDF cycles per day. Here the event is the outermost grouping, so
       a whole event's Event/Day/Window/Check header rows share one
       flat colour top to bottom. */
    .event-0 { background: #C6EFCE; color: #006100; }
    .event-1 { background: #FFEB9C; color: #9C6500; }
    .event-2 { background: #FFC7CE; color: #9C0006; }
    .group { page-break-after: always; }
    .group:last-child { page-break-after: auto; }
    .total-row td { font-weight: bold; background: #f1f5f9; border-top: 2px solid #4F81BD; }
    .right { text-align: right; }
    .idx { width: 24px; }
    .status { text-align: center; border-radius: 2px; }
    .status-present { background: #d1fae5; color: #065f46; font-weight: bold; }
    .status-late { background: #fef3c7; color: #92400e; }
    .status-absent { background: #fee2e2; color: #991b1b; }
    .status-excluded { background: #f3f4f6; color: #374151; }
    .status-pending { background: #f9fafb; color: #6b7280; }
    .status-reversed { background: #e0e7ff; color: #3730a3; }
</style>
</head>
<body>
@php
    // Event -> Day -> Window -> Check header tree — see
    // App\Domain\Support\RosterReportColumnGrouper::groupByEventDayAndWindow.
    // Only what's actually configured produces a column; nothing here
    // is padded with empty placeholder groups.
    $eventColumns = \App\Domain\Support\RosterReportColumnGrouper::groupByEventDayAndWindow($report['sessions']);
    $eventNames = implode(', ', array_column($report['events'], 'name'));
@endphp
@forelse ($report['groups'] as $group)
    <div class="group">
        <div class="banner"><h1>Master Roster Report — {{ $eventNames }}</h1></div>
        <div class="subtitle">{{ trim(trim($group['department_code'].(($group['major'] ?? null) ? ' '.$group['major'] : '')).' — Year '.($group['year_level'] ?: '—').' — Section '.($group['section'] ?: '—')) }}</div>
        <div class="meta">
            Generated {{ now()->format('M j, Y g:i A') }} •
            {{ count($group['students']) }} student{{ count($group['students']) === 1 ? '' : 's' }} •
            sorted A–Z by last name •
            {{ count($report['events']) }} event{{ count($report['events']) === 1 ? '' : 's' }} combined
        </div>

        <table>
            <thead>
                @if (count($eventColumns) > 0)
                    <tr>
                        <th class="idx static-header" rowspan="4">#</th>
                        <th class="static-header" rowspan="4">Student No.</th>
                        <th class="static-header" rowspan="4">Last Name</th>
                        <th class="static-header" rowspan="4">First Name</th>
                        @foreach ($eventColumns as $eventColumn)
                            <th class="event-header event-{{ $loop->index % 3 }}" colspan="{{ $eventColumn['span'] }}">{{ $eventColumn['event_name'] }}</th>
                        @endforeach
                        <th class="right penalty-header" rowspan="4">Penalty Total</th>
                    </tr>
                    <tr>
                        @foreach ($eventColumns as $eventColumn)
                            @foreach ($eventColumn['days'] as $day)
                                <th class="day-header event-{{ $loop->parent->index % 3 }}" colspan="{{ $day['span'] }}">Day {{ $day['day_number'] }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($eventColumns as $eventColumn)
                            @foreach ($eventColumn['days'] as $day)
                                @foreach ($day['windows'] as $window)
                                    <th class="window-header event-{{ $loop->parent->parent->index % 3 }}" colspan="{{ $window['span'] }}">{{ ucfirst($window['window_type']) }}</th>
                                @endforeach
                            @endforeach
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($eventColumns as $eventColumn)
                            @foreach ($eventColumn['days'] as $day)
                                @foreach ($day['windows'] as $window)
                                    @foreach ($window['checks'] as $check)
                                        <th class="check-header event-{{ $loop->parent->parent->parent->index % 3 }}">{{ $check['label'] }}</th>
                                    @endforeach
                                @endforeach
                            @endforeach
                        @endforeach
                    </tr>
                @else
                    <tr>
                        <th class="idx static-header">#</th>
                        <th class="static-header">Student No.</th>
                        <th class="static-header">Last Name</th>
                        <th class="static-header">First Name</th>
                        <th class="right penalty-header">Penalty Total</th>
                    </tr>
                @endif
            </thead>
            <tbody>
                @foreach ($group['students'] as $i => $student)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $student['student_number'] }}</td>
                        <td>{{ $student['last_name'] }}</td>
                        <td>{{ $student['first_name'] }}</td>
                        @foreach ($report['sessions'] as $session)
                            @php($status = $student['sessions'][$session['id']] ?? 'pending')
                            <td class="status status-{{ $status }}">{{ ucfirst($status) }}</td>
                        @endforeach
                        <td class="right">{{ number_format($student['penalty_total'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="total-row">
                    <td colspan="{{ 4 + count($report['sessions']) }}">Group Total</td>
                    <td class="right">{{ number_format($group['group_penalty_total'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@empty
    <div class="banner"><h1>Master Roster Report — {{ $eventNames }}</h1></div>
    <p style="padding: 10px;">No students found for the selected filters.</p>
@endforelse
</body>
</html>
