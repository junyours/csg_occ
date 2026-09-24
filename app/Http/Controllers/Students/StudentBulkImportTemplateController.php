<?php

namespace App\Http\Controllers\Students;

use App\Exports\StudentImportTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Students\ShowBulkImportTemplateRequest;
use Maatwebsite\Excel\Facades\Excel;

class StudentBulkImportTemplateController extends Controller
{
    public function show(ShowBulkImportTemplateRequest $request)
    {
        return Excel::download(new StudentImportTemplateExport, 'student-import-template.xlsx');
    }
}
