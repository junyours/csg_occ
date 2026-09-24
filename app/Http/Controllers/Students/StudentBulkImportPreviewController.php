<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\BulkImportStudents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\PreviewBulkImportStudentsRequest;

class StudentBulkImportPreviewController extends Controller
{
    /**
     * Validates the uploaded file and returns a row-by-row preview —
     * nothing is written to the database here. The admin reviews this
     * report client-side, then the same file is re-submitted to
     * StudentBulkImportController::store to actually commit it.
     */
    public function store(PreviewBulkImportStudentsRequest $request, BulkImportStudents $bulkImportStudents)
    {
        $preview = $bulkImportStudents->preview($request->file('files'), $request->user());

        return response()->json($preview);
    }
}
