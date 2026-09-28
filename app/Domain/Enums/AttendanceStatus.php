<?php

namespace App\Domain\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Missed = 'missed';
    case Excluded = 'excluded';
}
