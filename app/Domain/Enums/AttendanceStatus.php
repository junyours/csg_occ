<?php

namespace App\Domain\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excluded = 'excluded';
}
