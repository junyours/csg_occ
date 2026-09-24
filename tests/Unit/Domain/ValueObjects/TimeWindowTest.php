<?php

use App\Domain\Enums\AttendanceStatus;
use App\Domain\ValueObjects\TimeWindow;
use Carbon\Carbon;

it('classifies a scan at the exact window start as Present', function () {
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 07:00:00')))
        ->toBe(AttendanceStatus::Present);
});

it('classifies a scan one second before window start as Late', function () {
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 06:59:59')))
        ->toBe(AttendanceStatus::Late);
});

it('classifies a scan in the middle of the window as Present', function () {
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 07:30:00')))
        ->toBe(AttendanceStatus::Present);
});

it('classifies a scan at exactly the grace-period cutoff second as Present', function () {
    // window end 8:00 + 30 min grace = 8:30:00 exactly
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 08:30:00')))
        ->toBe(AttendanceStatus::Present);
});

it('classifies a scan one second past the grace-period cutoff as Late', function () {
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 08:30:01')))
        ->toBe(AttendanceStatus::Late);
});

it('classifies a scan well past the grace period as Late, not Absent', function () {
    // Absent is never decided by TimeWindow — only by manually ending a session.
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        30,
    );

    expect($window->classify(Carbon::parse('2026-11-10 08:45:00')))
        ->toBe(AttendanceStatus::Late);
});

it('handles a zero-minute grace period correctly at the boundary', function () {
    $window = new TimeWindow(
        Carbon::parse('2026-11-10 07:00:00'),
        Carbon::parse('2026-11-10 08:00:00'),
        0,
    );

    expect($window->classify(Carbon::parse('2026-11-10 08:00:00')))
        ->toBe(AttendanceStatus::Present)
        ->and($window->classify(Carbon::parse('2026-11-10 08:00:01')))
        ->toBe(AttendanceStatus::Late);
});
