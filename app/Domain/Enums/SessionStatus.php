<?php

namespace App\Domain\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Ongoing = 'ongoing';
    case Ended = 'ended';
}
