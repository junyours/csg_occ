<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $report['event']['name'] }} — Roster Report</title>
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
    th.day-header, th.window-header, th.check-header { text-align: center; }
    /* One flat colour per day, cycling — Excel's own built-in
       Good/Neutral/Bad cell styles, applied all the way down the
       Day/Window/Check header rows so each day reads as one coloured
       block top to bottom. */
    .day-0 { background: #C6EFCE; color: #006100; }
    .day-1 { background: #FFEB9C; color: #9C6500; }
    .day-2 { background: #FFC7CE; color: #9C0006; }
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
    // Dynamic Day -> Window -> Check header tree — a day with only a
    // Morning session never gets Afternoon/Evening columns, and a window
    // with only a time-in session never gets a Time Out column. See
    // App\Domain\Support\RosterReportColumnGrouper for why this is
    // derived here rather than in BuildEventRosterReport itself.
    $dayColumns = \App\Domain\Support\RosterReportColumnGrouper::groupByDayAndWindow($report['sessions']);
@endphp
@forelse ($report['groups'] as $group)
    <div class="group">
        <div class="banner"><h1>{{ $report['event']['name'] }} — Roster Report</h1></div>
        <div class="subtitle">{{ trim(trim($group['department_code'].(($group['major'] ?? null) ? ' '.$group['major'] : '')).' — Year '.($group['year_level'] ?: '—').' — Section '.($group['section'] ?: '—')) }}</div>
        <div class="meta">
            Generated {{ now()->format('M j, Y g:i A') }} •
            {{ count($group['students']) }} student{{ count($group['students']) === 1 ? '' : 's' }} •
            sorted A–Z by last name
        </div>

        <table>
            <thead>
                @if (count($dayColumns) > 0)
                    <tr>
                        <th class="idx static-header" rowspan="3">#</th>
                        <th class="static-header" rowspan="3">Student No.</th>
                        <th class="static-header" rowspan="3">Last Name</th>
                        <th class="static-header" rowspan="3">First Name</th>
                        @foreach ($dayColumns as $day)
                            <th class="day-header day-{{ $loop->index % 3 }}" colspan="{{ $day['span'] }}">Day {{ $day['day_number'] }}</th>
                        @endforeach
                        <th class="right penalty-header" rowspan="3">Penalty Total</th>
                    </tr>
                    <tr>
                        @foreach ($dayColumns as $day)
                            @foreach ($day['windows'] as $window)
                                <th class="window-header day-{{ $loop->parent->index % 3 }}" colspan="{{ $window['span'] }}">{{ ucfirst($window['window_type']) }}</th>
                            @endforeach
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($dayColumns as $day)
                            @foreach ($day['windows'] as $window)
                                @foreach ($window['checks'] as $check)
                                    <th class="check-header day-{{ $loop->parent->parent->index % 3 }}">{{ $check['label'] }}</th>
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
    <div class="banner"><h1>{{ $report['event']['name'] }}</h1></div>
    <p style="padding: 10px;">No students found for the selected filters.</p>
@endforelse
</body>
</html>
