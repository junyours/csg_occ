<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Actions\Dashboard\BuildDashboardSummary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Always the authenticated caller's own role-scoped view — like
     * /auth/me and EventMyAttendanceController, nothing here can reveal
     * another account's data, so no extra Gate is needed beyond being
     * logged in (and past the password-change gate).
     */
    public function show(Request $request, BuildDashboardSummary $buildDashboardSummary)
    {
        return response()->json($buildDashboardSummary($request->user()));
    }
}
