<?php

namespace App\Http\Controllers\Students;

use App\Application\Actions\Students\BulkImportStudents;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\BulkImportStudentsRequest;

class StudentBulkImportController extends Controller
{
    public function store(BulkImportStudentsRequest $request, BulkImportStudents $bulkImportStudents)
    {
        $report = $bulkImportStudents($request->file('files'), $request->user());

        return response()->json($report);
    }
}
