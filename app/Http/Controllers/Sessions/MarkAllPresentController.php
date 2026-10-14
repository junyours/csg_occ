<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\MarkAllStudentsPresent;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use Illuminate\Support\Facades\Gate;

class MarkAllPresentController extends Controller
{
    public function store(AttendanceSession $session, MarkAllStudentsPresent $markAllStudentsPresent)
    {
        // No request body — same reasoning as EndSessionController.
        Gate::authorize('markAllPresent', AttendanceSession::class);

        return response()->json($markAllStudentsPresent($session, request()->user()));
    }
}
