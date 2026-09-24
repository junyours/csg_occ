<?php

namespace App\Domain\Enums;

enum WindowType: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
}
