<?php

namespace App\Domain\Enums;

/**
 * student-exclusion-feature-plan.md §6: removing an exclusion is a soft
 * state change, not a delete — history (which sessions were already
 * marked Excluded while it was active) must survive removal untouched.
 */
enum ExclusionStatus: string
{
    case Active = 'active';
    case Removed = 'removed';
}
