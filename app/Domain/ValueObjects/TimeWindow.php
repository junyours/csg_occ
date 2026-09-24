<?php

namespace App\Domain\ValueObjects;

use App\Domain\Enums\AttendanceStatus;
use Carbon\Carbon;

final class TimeWindow
{
    public function __construct(
        private readonly Carbon $start,
        private readonly Carbon $end,
        private readonly int $graceMinutes,
    ) {}

    /**
     * Classify a scan timestamp against this window.
     * Only ever returns Present or Late — Absent is decided
     * separately, at session-end, for students with no scan at all.
     */
    public function classify(Carbon $scannedAt): AttendanceStatus
    {
        $graceEnd = $this->end->copy()->addMinutes($this->graceMinutes);

        if ($scannedAt->greaterThanOrEqualTo($this->start) && $scannedAt->lessThanOrEqualTo($graceEnd)) {
            return AttendanceStatus::Present;
        }

        return AttendanceStatus::Late;
    }

    public function graceEnd(): Carbon
    {
        return $this->end->copy()->addMinutes($this->graceMinutes);
    }
}
