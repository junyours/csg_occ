<?php

use App\Domain\Support\RosterReportColumnGrouper;

it('sums each semester\'s event columns into one merged header span, in column order', function () {
    $eventColumns = [
        ['event_id' => 1, 'span' => 2],
        ['event_id' => 2, 'span' => 3],
        ['event_id' => 3, 'span' => 1],
    ];
    $semesters = [
        ['label' => '2026-2027 — Semester 1 (current)', 'event_ids' => [1, 2]],
        ['label' => '2025-2026 — Semester 2', 'event_ids' => [3]],
    ];

    expect(RosterReportColumnGrouper::groupBySemester($eventColumns, $semesters))->toBe([
        ['label' => '2026-2027 — Semester 1 (current)', 'span' => 5],
        ['label' => '2025-2026 — Semester 2', 'span' => 1],
    ]);
});

it('drops a semester that has no session columns', function () {
    $eventColumns = [['event_id' => 1, 'span' => 2]];
    $semesters = [
        ['label' => 'Has columns', 'event_ids' => [1]],
        ['label' => 'Events with no sessions', 'event_ids' => [9]],
    ];

    expect(RosterReportColumnGrouper::groupBySemester($eventColumns, $semesters))->toBe([
        ['label' => 'Has columns', 'span' => 2],
    ]);
});
