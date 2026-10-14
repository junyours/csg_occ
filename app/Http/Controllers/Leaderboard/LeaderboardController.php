<?php

namespace App\Http\Controllers\Leaderboard;

use App\Application\Actions\Attendance\BuildLeaderboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeaderboardController extends Controller
{
    /**
     * School-wide streak leaderboard, top 20 for the chosen scope plus the
     * caller's own rank. Like the dashboard it only ever reveals what any
     * logged-in account may see (a ranking of names and streaks), so being
     * authenticated and past the password/photo gates is the whole check.
     */
    public function show(Request $request, BuildLeaderboard $buildLeaderboard)
    {
        $filters = $request->validate([
            'scope' => ['nullable', 'string', Rule::in(BuildLeaderboard::SCOPES)],
        ]);

        return response()->json($buildLeaderboard($request->user(), $filters['scope'] ?? 'overall'));
    }
}
