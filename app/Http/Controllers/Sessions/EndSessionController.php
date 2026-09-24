<?php

namespace App\Http\Controllers\Sessions;

use App\Application\Actions\Sessions\EndSession;
use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;

class EndSessionController extends Controller
{
    public function store(AttendanceSession $session, EndSession $endSession)
    {
        // No request body needed — ending a session takes no input beyond
        // which session, so there's no FormRequest here (unlike ScanController).
        $summary = $endSession($session);

        return response()->json(array_merge($summary, [
            'status' => $session->fresh()->status->value,
        ]));
    }
}
