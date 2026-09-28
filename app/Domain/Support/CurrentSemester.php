<?php

namespace App\Domain\Support;

use App\Models\AcademicYear;
use App\Models\Semester;

/** "Current semester" = active semester of the active academic year. */
final class CurrentSemester
{
    public static function get(): ?Semester
    {
        $academicYear = AcademicYear::active()->first();

        return $academicYear
            ? Semester::active()->forAcademicYear($academicYear->id)->first()
            : null;
    }

    public static function id(): ?int
    {
        return self::get()?->id;
    }

    public static function isCurrent(?int $semesterId): bool
    {
        return $semesterId !== null && $semesterId === self::id();
    }

    /** Events with no semester (legacy) keep the live-roster behavior. */
    public static function usesLiveRoster(?int $semesterId): bool
    {
        return $semesterId === null || self::isCurrent($semesterId);
    }
}
