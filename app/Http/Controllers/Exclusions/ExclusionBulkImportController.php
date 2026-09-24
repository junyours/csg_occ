<?php

namespace App\Http\Controllers\Exclusions;

use App\Application\Actions\Exclusions\BulkCreateExclusions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exclusions\BulkExclusionsRequest;
use App\Models\EventModel;

class ExclusionBulkImportController extends Controller
{
    /**
     * Commits a bulk-exclusion CSV (student-exclusion-feature-plan.md
     * §5) — the frontend is expected to have already shown the row-by-
     * row preview from ExclusionBulkImportPreviewController before
     * calling this (§7: "no silent bulk actions").
     */
    public function store(EventModel $event, BulkExclusionsRequest $request, BulkCreateExclusions $bulkCreateExclusions)
    {
        $report = $bulkCreateExclusions($request->file('file'), $event, $request->string('reason')->toString(), $request->user());

        return response()->json($report);
    }
}
