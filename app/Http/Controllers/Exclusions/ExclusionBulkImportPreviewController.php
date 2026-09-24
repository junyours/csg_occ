<?php

namespace App\Http\Controllers\Exclusions;

use App\Application\Actions\Exclusions\BulkCreateExclusions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exclusions\PreviewBulkExclusionsRequest;
use App\Models\EventModel;

class ExclusionBulkImportPreviewController extends Controller
{
    /**
     * Validates the uploaded bulk-exclusion CSV and returns a row-by-row
     * report — nothing is written to the database here. Backs the
     * required preview step (student-exclusion-feature-plan.md §5 step
     * 5, §7): "12 students will be excluded from Day 2 — Reason: [x] —
     * confirm?"
     */
    public function store(EventModel $event, PreviewBulkExclusionsRequest $request, BulkCreateExclusions $bulkCreateExclusions)
    {
        $preview = $bulkCreateExclusions->preview($request->file('file'), $event, $request->user());

        return response()->json($preview);
    }
}
