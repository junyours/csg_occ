<?php

namespace App\Domain\Enums;

/**
 * Which half of a window a session covers. Each AttendanceSession is now
 * exactly one check — a "morning time-in" and a "morning time-out" are two
 * separate session rows sharing the same window_type, distinguished by
 * this column (unique(event_day_id, window_type, check_type)) — rather
 * than one session row carrying both a time-in and a time-out sub-window.
 */
enum CheckType: string
{
    case TimeIn = 'time_in';
    case TimeOut = 'time_out';
}
