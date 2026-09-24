<?php

use App\Http\Controllers\AcademicYears\AcademicYearController;
use App\Http\Controllers\Attendance\AttendanceHistoryController;
use App\Http\Controllers\Attendance\AttendanceHistoryFilterOptionsController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Departments\DepartmentController;
use App\Http\Controllers\Departments\DepartmentLogoController;
use App\Http\Controllers\Events\EndEventController;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\Events\EventDayController;
use App\Http\Controllers\Events\EventMyAttendanceController;
use App\Http\Controllers\Events\EventRosterReportController;
use App\Http\Controllers\Events\MyAttendanceHistoryController;
use App\Http\Controllers\Events\RescheduleEventDaysController;
use App\Http\Controllers\Exclusions\ExclusionBulkImportController;
use App\Http\Controllers\Exclusions\ExclusionBulkImportPreviewController;
use App\Http\Controllers\Exclusions\ExclusionController;
use App\Http\Controllers\Penalties\MyPenaltyHistoryController;
use App\Http\Controllers\Penalties\PenaltyController;
use App\Http\Controllers\Penalties\ReversePenaltyController;
use App\Http\Controllers\Reports\MasterReportController;
use App\Http\Controllers\Reports\MasterReportGenerationController;
use App\Http\Controllers\Reports\RosterReportGenerationController;
use App\Http\Controllers\Semesters\SemesterController;
use App\Http\Controllers\Sessions\EndSessionController;
use App\Http\Controllers\Sessions\RecentScansController;
use App\Http\Controllers\Sessions\ReverseScanController;
use App\Http\Controllers\Sessions\ScanController;
use App\Http\Controllers\Sessions\SessionController;
use App\Http\Controllers\Sessions\SessionReportController;
use App\Http\Controllers\Sessions\StartSessionController;
use App\Http\Controllers\Sessions\WindowController;
use App\Http\Controllers\Students\StudentBulkImportController;
use App\Http\Controllers\Students\StudentBulkImportPreviewController;
use App\Http\Controllers\Students\StudentBulkImportTemplateController;
use App\Http\Controllers\Students\StudentController;
use App\Http\Controllers\Students\StudentPhotoController;
use App\Http\Controllers\Students\StudentQrController;
use App\Http\Controllers\Students\StudentRoleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Reachable even while must_change_password is still true — otherwise a
    // student stuck on their default password could never clear the gate,
    // or log out, from the API alone.
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);
    Route::post('/auth/accept-terms', [AuthController::class, 'acceptTerms']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::middleware('password.changed')->group(function () {
        // Reachable even while the photo gate below is still open —
        // otherwise a student would have no way to ever satisfy it.
        // Mirrors why /auth/change-password sits outside password.changed
        // above: the one action that clears a gate can never itself be
        // behind that gate.
        Route::post('/students/{student}/photo', [StudentPhotoController::class, 'update']);

        Route::middleware('photo.uploaded')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'show']);

            Route::post('/sessions/{session}/scan', [ScanController::class, 'store']);

            // The scanning screen's own two companions to the scan endpoint,
            // both scoped to a single session on purpose (see BuildRecentScans):
            //  - GET  .../recent-scans        the last few badges read into
            //    *this* session, so an officer never sees rows from another
            //    session or another event in the strip they're about to
            //    reverse something from.
            //  - POST .../records/{record}/reverse  undo one of those scans
            //    when the QR turns out to belong to someone who isn't the
            //    person holding it. The record is deleted (back to pending,
            //    re-scannable by its real owner) and an audit row is written
            //    — see ReverseAttendanceRecord for why deletion, not a flag.
            // {record} is bound independently of {session}, so the action
            // re-checks that the record actually belongs to the session
            // rather than trusting the URL.
            Route::get('/sessions/{session}/recent-scans', [RecentScansController::class, 'index']);
            Route::post('/sessions/{session}/records/{record}/reverse', [ReverseScanController::class, 'store']);

            Route::post('/sessions/{session}/start', [StartSessionController::class, 'store']);
            Route::post('/sessions/{session}/end', [EndSessionController::class, 'store']);
            Route::get('/sessions/{session}/report', [SessionReportController::class, 'show']);

            // event-day-window-edit-delete-plan.md §4.3: edit or delete a
            // single check (e.g. just "Morning Time In") while it's still
            // Scheduled — see UpdateSession/DeleteSession.
            Route::patch('/sessions/{session}', [SessionController::class, 'update']);
            Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);

            // §4.3b: convenience endpoint deleting every still-Scheduled
            // check of one window in one call — see DeleteWindow.
            Route::delete('/event-days/{eventDay}/windows/{windowType}', [WindowController::class, 'destroy']);

            // Manage Exclusions screen (student-exclusion-feature-plan.md
            // §4B): every exclusion — active or removed — recorded for one
            // event. §4A's optional "Excluded Students" step inside the
            // event-creation wizard is not built — there is no multi-step
            // creation wizard at all (event creation is a single form) — so
            // this screen is currently the only place exclusions are added.
            Route::get('/events/{event}/exclusions', [ExclusionController::class, 'index']);
            Route::post('/exclusions', [ExclusionController::class, 'store']);
            Route::delete('/exclusions/{exclusion}', [ExclusionController::class, 'destroy']);

            // Bulk exclusion upload (student-exclusion-feature-plan.md
            // §5): preview validates the CSV row-by-row and writes nothing
            // (§7's required preview-before-commit); the second call
            // actually creates the batch, each row going through the exact
            // same CreateExclusion rules as a single manual add.
            Route::post('/events/{event}/exclusions/bulk/preview', [ExclusionBulkImportPreviewController::class, 'store']);
            Route::post('/events/{event}/exclusions/bulk', [ExclusionBulkImportController::class, 'store']);

            // Audit trail: who scanned each attendance record and when,
            // filterable by course/major/year level/section, scoped to one
            // event or across all of them (see BuildAttendanceHistory). Same
            // viewReport ability and SC Admin department-scoping as the rest
            // of the admin reporting surface.
            Route::get('/attendance-history', [AttendanceHistoryController::class, 'index']);

            // Distinct major/year-level/section values already on file, for
            // the ledger's free-text filters to autosuggest against as the
            // admin types. Same viewReport gate and SC Admin scoping as the
            // ledger itself.
            Route::get('/attendance-history/filter-options', [AttendanceHistoryFilterOptionsController::class, 'index']);

            // Spec §7.3: the admin worklist a CSG Admin reads before deciding
            // what to reverse — every penalty across every student, filterable
            // by department/event/reversed-state/search (see BuildPenaltyLedger).
            Route::get('/penalties', [PenaltyController::class, 'index']);

            // Spec §7.3: CSG Admin manually excuses a penalty after the fact.
            // Reversal is one-way (see ReversePenalty/PenaltyAlreadyReversedException),
            // so this is a single PATCH rather than a toggle.
            Route::patch('/penalties/{penalty}/reverse', [ReversePenaltyController::class, 'update']);

            Route::get('/academic-years', [AcademicYearController::class, 'index']);
            Route::post('/academic-years', [AcademicYearController::class, 'store']);
            Route::patch('/academic-years/{academicYear}', [AcademicYearController::class, 'update']);
            Route::post('/academic-years/{academicYear}/activate', [AcademicYearController::class, 'activate']);
            Route::post('/academic-years/{academicYear}/deactivate', [AcademicYearController::class, 'deactivate']);

            Route::get('/academic-years/{academicYear}/semesters', [SemesterController::class, 'index']);
            Route::post('/academic-years/{academicYear}/semesters', [SemesterController::class, 'store']);
            Route::patch('/semesters/{semester}', [SemesterController::class, 'update']);
            Route::post('/semesters/{semester}/activate', [SemesterController::class, 'activate']);
            Route::post('/semesters/{semester}/deactivate', [SemesterController::class, 'deactivate']);

            Route::get('/departments', [DepartmentController::class, 'index']);
            Route::post('/departments', [DepartmentController::class, 'store']);
            Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
            Route::post('/departments/{department}/logo', [DepartmentLogoController::class, 'update']);
            Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);

            Route::get('/students', [StudentController::class, 'index']);
            Route::post('/students', [StudentController::class, 'store']);
            Route::get('/students/bulk-import-template', [StudentBulkImportTemplateController::class, 'show']);
            Route::post('/students/bulk-import/preview', [StudentBulkImportPreviewController::class, 'store']);
            Route::post('/students/bulk-import', [StudentBulkImportController::class, 'store']);
            Route::patch('/students/{student}/role', [StudentRoleController::class, 'update']);
            Route::get('/students/{student}/qr', [StudentQrController::class, 'show']);

            Route::get('/events', [EventController::class, 'index']);
            Route::post('/events', [EventController::class, 'store']);
            Route::post('/events/{event}/end', [EndEventController::class, 'store']);

            // event-day-window-edit-delete-plan.md §4.1: name/description
            // only, guarded by event-not-ended — see UpdateEvent.
            Route::patch('/events/{event}', [EventController::class, 'update']);

            // Whole-event delete — for a mistakenly-created event. Refused
            // if the event has ended, if any session anywhere under it has
            // started or ended, or if any exclusion/report record already
            // references it — see DeleteEvent.
            Route::delete('/events/{event}', [EventController::class, 'destroy']);

            Route::post('/events/{event}/days', [EventDayController::class, 'store']);

            // §4.2: date only, guarded by event-not-ended + no started/ended
            // session under the day — see UpdateEventDay. Delete cascades
            // Day/Window-scope exclusion soft-removal (§4.4) — see
            // DeleteEventDay.
            Route::patch('/event-days/{eventDay}', [EventDayController::class, 'update']);
            Route::delete('/event-days/{eventDay}', [EventDayController::class, 'destroy']);

            // §4.5: batch, all-or-nothing date shift across several days of
            // one event — the resolution path for §4.2's single-day-edit
            // date collision — see RescheduleEventDays.
            Route::patch('/events/{event}/reschedule', [RescheduleEventDaysController::class, 'update']);

            Route::post('/event-days/{eventDay}/sessions', [SessionController::class, 'store']);
            Route::get('/events/{event}/roster-report', [EventRosterReportController::class, 'show']);
            Route::get('/events/{event}/my-attendance', [EventMyAttendanceController::class, 'show']);
            Route::get('/my-attendance-history', [MyAttendanceHistoryController::class, 'show']);
            Route::get('/my-penalty-history', [MyPenaltyHistoryController::class, 'show']);

            // Async roster report generation: starts near-instantly (just a
            // tracking row) and finishes after the response is sent — see
            // StartRosterReportGeneration — with the frontend polling `show`
            // for a real, DB-backed progress bar rather than waiting on a
            // single long request that can hang or time out on a big export.
            Route::post('/events/{event}/report-generations', [RosterReportGenerationController::class, 'store']);
            Route::get('/report-generations/{reportGeneration}', [RosterReportGenerationController::class, 'show'])
                ->name('report-generations.show');
            Route::get('/report-generations/{reportGeneration}/download', [RosterReportGenerationController::class, 'download'])
                ->name('report-generations.download');

            // Settings → Reports landing screen: every event in the current
            // active academic year + semester with its total
            // Present/Absent/Late/Excluded (see BuildMasterReport) —
            // distinct from the per-event roster report above.
            Route::get('/reports/master', [MasterReportController::class, 'show']);

            // The master report's own "generate" action, living on the
            // Reports screen itself rather than inside one event: combines
            // several event_ids into a single file (see
            // StartMasterReportGeneration/BuildMasterRosterReport). Reuses
            // the same report-generations.show/download routes above to
            // poll and download — a ReportGeneration row behaves the same
            // whether it came from here or from the per-event flow.
            Route::post('/reports/master/report-generations', [MasterReportGenerationController::class, 'store']);
        });
    });
});
