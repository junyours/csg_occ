<?php

namespace App\Http\Controllers\Exclusions;

use App\Application\Actions\Exclusions\BuildExclusionWarnings;
use App\Application\Actions\Exclusions\CreateExclusion;
use App\Application\Actions\Exclusions\ListExclusionsForEvent;
use App\Application\Actions\Exclusions\RemoveExclusion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exclusions\StoreExclusionRequest;
use App\Models\EventModel;
use App\Models\Exclusion;
use Illuminate\Support\Facades\Gate;

class ExclusionController extends Controller
{
    /**
     * Manage Exclusions screen data (student-exclusion-feature-plan.md
     * §4B) — every exclusion recorded for this event, active or removed.
     */
    public function index(EventModel $event, ListExclusionsForEvent $listExclusions)
    {
        Gate::authorize('viewAny', Exclusion::class);

        return response()->json($listExclusions($event));
    }

    public function store(
        StoreExclusionRequest $request,
        CreateExclusion $createExclusion,
        BuildExclusionWarnings $buildWarnings,
    ) {
        $exclusion = $createExclusion($request->validated(), $request->user());

        // The exclusion itself stays at the top level of the response —
        // callers that only care about the created row are unaffected.
        // `warnings` rides alongside it: student-exclusion-feature-plan.md
        // §6a point 1 wants CSG told when the exclusion cannot take effect
        // for a window the student already scanned into. It is advisory
        // only; the creation succeeded either way, so this is never an
        // error status.
        return response()->json(
            array_merge(
                $exclusion->fresh(['student', 'event', 'eventDay', 'createdBy'])->toArray(),
                ['warnings' => $buildWarnings($exclusion)],
            ),
            201,
        );
    }

    public function destroy(Exclusion $exclusion, RemoveExclusion $removeExclusion)
    {
        // No dedicated FormRequest for removal (no body to validate, same
        // reasoning as EndSessionController) — gate it directly instead.
        Gate::authorize('remove', $exclusion);

        $removeExclusion($exclusion, request()->user());

        return response()->noContent();
    }
}
