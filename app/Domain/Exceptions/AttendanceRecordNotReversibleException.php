<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A scan can only be undone while the session it belongs to is still
 * Ongoing. Once EndSession has run, the session's Absent sweep and its
 * penalty ledger writes have already happened (see EndSession), so
 * deleting a record afterwards would leave that student with *no* row at
 * all for the session — neither Present nor Absent — silently dropping
 * them out of every report and out of the absent penalty they'd otherwise
 * have been charged. Reversal is therefore a live, at-the-gate correction
 * only; anything after the fact is a CSG Admin penalty reversal instead
 * (spec §7.3, see ReversePenalty).
 *
 * Message is parameterized rather than fixed because the same 409 covers
 * a small family of "this particular row can't be undone" cases and the
 * officer at the gate should see which one they hit.
 */
final class AttendanceRecordNotReversibleException extends RuntimeException
{
    public function __construct(string $message = 'This scan can no longer be reversed.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
