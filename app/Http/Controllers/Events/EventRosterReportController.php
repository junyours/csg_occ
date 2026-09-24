<?php

namespace App\Http\Controllers\Events;

use App\Application\Actions\Reports\BuildEventRosterReport;
use App\Domain\Contracts\RosterPdfRendererInterface;
use App\Domain\Enums\Role;
use App\Exports\EventRosterReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Events\ShowEventRosterReportRequest;
use App\Models\EventModel;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class EventRosterReportController extends Controller
{
    /**
     * The report request: a printable roster per department/year
     * level/section, as either an Excel workbook (default, one sheet
     * per group) or a PDF (one printable page per group) — no queue
     * involved, since the report explicitly rules that out for shared
     * hosting, so this runs synchronously in the request thread like
     * the bulk-import template download already does.
     */
    public function show(
        ShowEventRosterReportRequest $request,
        EventModel $event,
        BuildEventRosterReport $buildEventRosterReport,
        RosterPdfRendererInterface $pdfRenderer,
    ) {
        $user = $request->user();

        // Spec §10's "CSG sees all, SC sees own dept" rule (already
        // applied the same way in SessionReportController): an SC Admin
        // never gets to pass a different department_id through the
        // query string.
        $departmentId = $user->role === Role::ScAdmin
            ? $user->sc_admin_department_id
            : ($request->integer('department_id') ?: null);

        $report = $buildEventRosterReport(
            $event,
            $departmentId,
            $request->string('major')->value() ?: null,
            $request->string('year_level')->value() ?: null,
            $request->string('section')->value() ?: null,
        );

        $filename = Str::slug($event->name).'-roster-report';

        if ($request->input('format') === 'pdf') {
            return response($pdfRenderer->render($report), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            ]);
        }

        return Excel::download(new EventRosterReportExport($report), $filename.'.xlsx');
    }
}
