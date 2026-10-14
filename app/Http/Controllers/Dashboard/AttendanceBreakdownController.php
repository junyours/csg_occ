<?php

namespace App\Http\Controllers\Dashboard;

use App\Application\Actions\Dashboard\BuildAttendanceBreakdown;
use App\Domain\Enums\Role;
use App\Domain\Support\SemesterScope;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttendanceBreakdownController extends Controller
{
    /**
     * Admin dashboard only (System / CSG / SC Admin) — the same people who
     * get the admin variant of GET /dashboard, and SC Admin is held to their
     * own department exactly as there. Students and officers get 403: they
     * never see these cards.
     *
     * `event_day_id` must belong to `event_id` when both are sent. With
     * neither, the numbers cover the semester scope (current by default).
     */
    public function show(Request $request, BuildAttendanceBreakdown $buildAttendanceBreakdown)
    {
        $user = $request->user();

        abort_unless(in_array($user->role, [Role::SystemAdmin, Role::CsgAdmin, Role::ScAdmin], true), 403);

        $eventId = $request->integer('event_id') ?: null;

        $filters = $request->validate([
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'event_day_id' => [
                'nullable',
                'integer',
                $eventId
                    ? Rule::exists('event_days', 'id')->where('event_id', $eventId)
                    : 'exists:event_days,id',
            ],
            'semester_id' => ['nullable', 'integer', 'exists:semesters,id'],
            'scope' => ['nullable', 'string', 'in:all'],
        ]);

        return response()->json($buildAttendanceBreakdown(
            $user->role === Role::ScAdmin ? $user->sc_admin_department_id : null,
            isset($filters['event_id']) ? (int) $filters['event_id'] : null,
            isset($filters['event_day_id']) ? (int) $filters['event_day_id'] : null,
            SemesterScope::resolve($user, $filters['scope'] ?? null, $filters['semester_id'] ?? null),
        ));
    }
}
