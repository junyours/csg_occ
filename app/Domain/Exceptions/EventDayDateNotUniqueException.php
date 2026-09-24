<?php

namespace App\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * event-day-window-edit-delete-plan.md §4.2/§4.5: a Day's date must stay
 * unique within its event. UpdateEventDayRequest already enforces this
 * with a `Rule::unique(...)->ignore($eventDay->id)` validation rule for
 * the plain single-day edit — this exception is for RescheduleEventDays
 * instead, which re-checks the *entire* target date set with fresh
 * `lockForUpdate()` reads at commit time (Bug #11), inside a
 * DB::transaction() rather than a FormRequest, where a 422 validation
 * error isn't available. 409 (not 422) for consistency with every other
 * domain exception this feature introduces (§6/§8 point 6).
 */
final class EventDayDateNotUniqueException extends RuntimeException
{
    public function __construct(string $message = 'That date is already used by another day in this event.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
