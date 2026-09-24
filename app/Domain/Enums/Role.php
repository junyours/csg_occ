<?php

namespace App\Domain\Enums;

enum Role: string
{
    case SystemAdmin = 'system_admin';
    case CsgAdmin = 'csg_admin';
    case ScAdmin = 'sc_admin';
    case Officer = 'officer';
    case Student = 'student';
}
