<?php

namespace App\Domain\Enums;

enum PasswordResetCodeStatus: string
{
    /** Usable right now: not expired, not spent, attempts left. */
    case Pending = 'pending';
    /** Not expired, but burned by too many wrong guesses. */
    case Locked = 'locked';
    /** Spent — the student entered it correctly (single-use). */
    case Used = 'used';
    /** Past its 5-minute window without being used. */
    case Expired = 'expired';
}
