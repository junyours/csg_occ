<?php

namespace App\Domain\Support;

use App\Domain\Enums\Semester as SemesterEnum;
use App\Models\Semester;

/**
 * Human label for a semester on printed/exported reports, e.g.
 * "2026-2027 — Semester 2". Same wording as the frontend's
 * SEMESTER_LABEL, so a report and the screen it was generated from
 * name a semester identically. "(current)" is appended by the caller,
 * since only the caller knows whether it wants it.
 */
final class SemesterLabel
{
    public static function for(?Semester $semester): string
    {
        if ($semester === null) {
            return 'No semester';
        }

        $term = match ($semester->name) {
            SemesterEnum::First => 'Semester 1',
            SemesterEnum::Second => 'Semester 2',
            SemesterEnum::Summer => 'Summer',
        };

        $year = $semester->academicYear?->name;

        return $year ? $year.' — '.$term : $term;
    }
}
