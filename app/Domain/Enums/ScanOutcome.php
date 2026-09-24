<?php

namespace App\Domain\Enums;

/**
 * ScanAttendance itself stays a pure idempotent "make it so" action (see
 * its test suite) and doesn't report what it did. ScanController derives
 * this from Eloquent's own change-tracking on the record it gets back
 * (wasRecentlyCreated), so the scanning screen can tell an officer apart:
 *
 * - Recorded: first scan for this student in this session — a fresh
 *   check-in, classified Present or Late against the session's window.
 * - Duplicate: the student already has a record for this session (they
 *   already scanned, or the session was ended and they were marked
 *   Absent/Excluded before this scan came in). No write happened; this is
 *   the "same badge scanned twice" case the scanner UI should flag
 *   distinctly rather than showing a plain "Present" as if it were new.
 */
enum ScanOutcome: string
{
    case Recorded = 'recorded';
    case Duplicate = 'duplicate';
}
