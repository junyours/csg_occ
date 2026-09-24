<?php

namespace App\Domain\Enums;

/**
 * student-exclusion-feature-plan.md §3. Window means one specific
 * day + window_type ("Day 2 Morning"), covering whichever of that
 * window's time-in/time-out AttendanceSession rows exist — not a single
 * check the way the old `session` scope was. Day means one specific
 * EventDay, all its windows. Event means the whole event, cascading
 * forward per §6a (see Exclusion::excludedStudentIdsForSession).
 */
enum ExclusionScope: string
{
    case Event = 'event';
    case Day = 'day';
    case Window = 'window';
}
