<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\StartSession;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;

class StartSessionController extends Controller
{
    public function store(AttendanceSession $session, StartSession $startSession)
    {
        // No request body needed — same reasoning as EndSessionController.
        $started = $startSession($session);

        return response()->json($started);
    }
}
