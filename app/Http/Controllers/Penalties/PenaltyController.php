<?php

namespace App\Http\Controllers\Penalties;

use App\Application\Actions\Penalties\BuildPenaltyLedger;
use App\Application\Actions\Penalties\BuildPenaltySummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Penalties\IndexPenaltyRequest;

class PenaltyController extends Controller
{
    public function index(IndexPenaltyRequest $request, BuildPenaltyLedger $buildPenaltyLedger, BuildPenaltySummary $buildPenaltySummary)
    {
        $filters = $request->validated();

        // Merged onto the paginator's own array shape (data/current_page/
        // etc.) as a sibling "summary" key, rather than nesting the
        // ledger under its own key — that would be a breaking response
        // shape change for the existing /penalties consumers.
        //
        // JSON_PRESERVE_ZERO_FRACTION: BuildPenaltySummary documents
        // `total` as float, but PHP's json_encode silently drops the
        // decimal point on a whole-number float (50.0 -> "50" on the
        // wire), so a client decoding the response gets an int back for
        // any total that happens to be round. Without this flag the
        // response's actual type contradicts its documented type
        // exactly when the number looks "too clean" to need a decimal —
        // the one time a naive test is most likely to hardcode a round
        // total and catch it (see PenaltyControllerTest).
        return response()->json([
            ...$buildPenaltyLedger($filters)->toArray(),
            'summary' => $buildPenaltySummary($filters),
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
