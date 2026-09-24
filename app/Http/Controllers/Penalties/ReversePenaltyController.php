<?php

namespace App\Http\Controllers\Penalties;

use App\Application\Actions\Penalties\ReversePenalty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Penalties\ReversePenaltyRequest;
use App\Models\AttendancePenalty;

class ReversePenaltyController extends Controller
{
    public function update(ReversePenaltyRequest $request, AttendancePenalty $penalty, ReversePenalty $reversePenalty)
    {
        $reversed = $reversePenalty($penalty, $request->validated('reason'), $request->user());

        // Only `student` is eager-loaded here — not `reversedBy`. That
        // relation's name snake-cases to the same "reversed_by" JSON key
        // the plain reversed_by (admin id) column already uses, and
        // Eloquent's toArray() lets the loaded relation silently
        // overwrite the column value with the full related model. The
        // id alone is enough for the caller; BuildMyPenaltyHistory is
        // where a display name gets resolved, via its own explicit
        // array shape that sidesteps this collision entirely.
        return response()->json($reversed->fresh(['student']));
    }
}
