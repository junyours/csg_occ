<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Thrown when a CSG Admin tries to start a session before its scheduled
 * start time.
 *
 * The frontend already disables the "Start" button until the session's
 * start time is reached, but that check runs against the *device's* clock,
 * which an admin could set back to force the button active early. This
 * exception is the real gate: StartSession compares the session's own
 * start_time (combined with its event day's date) against Carbon::now() —
 * this application server's system clock — so no client-controlled value
 * ever factors into the decision. Starting early would otherwise let
 * attendance be taken before the session's own window opens, which
 * TimeWindow::classify() would then mark Late instead of Present for
 * everyone who scans in that gap.
 */
final class SessionStartTooEarlyException extends RuntimeException
{
    public function __construct(
        string $message = 'This session cannot be started before its scheduled start time.'
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
