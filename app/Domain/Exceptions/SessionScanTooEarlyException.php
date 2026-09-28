<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a scan lands on an Ongoing session before that session's own
 * scheduled start time.
 *
 * StartSession already refuses to move a session to Ongoing early (see
 * SessionStartTooEarlyException), so this should be unreachable in normal
 * operation. It exists as a second, independent server-clock check at the
 * point of scanning: an officer's scanning device's clock is never trusted
 * for anything here — ScanAttendance always classifies against
 * Carbon::now() — but this guard means that even if a session somehow ended
 * up Ongoing ahead of its start (a bug, a manual DB edit, a race), a scan
 * still can't be recorded before the window legitimately opens. Distinct
 * from SessionNotAcceptingScansException (which covers Scheduled/Ended
 * sessions) so the officer's scanner can show "wait for the session to
 * start" instead of a generic rejection.
 */
final class SessionScanTooEarlyException extends RuntimeException
{
    public function __construct(
        string $message = 'This session has not started yet. Please wait for the session to open before scanning.'
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
