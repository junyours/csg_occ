<?php

namespace App\Application\Actions\Attendance;

use App\Domain\Enums\Role;
use App\Domain\Enums\SessionStatus;
use App\Domain\Support\CurrentSemester;
use App\Models\AttendanceSession;
use App\Models\Student;
use Carbon\Carbon;

final class BuildLeaderboard
{
    public const SCOPES = ['overall', 'today', 'session'];

    /** The Leaderboard screen only ever lists this many. */
    public const LIMIT = 20;

    public function __construct(
        private readonly BuildStreakLeaderboard $buildStreakLeaderboard = new BuildStreakLeaderboard
    ) {
    }

    /**
     * Payload for the Leaderboard screen. Points are the attendance streak
     * in every scope; what changes is the window the streak is measured in:
     *
     *  - overall: the running streak right now (current semester).
     *  - today:   the streak earned across today's sessions only (Asia/Manila
     *             date, same zone every session time in the app uses).
     *  - session: each student's running streak as of the end of the
     *             session that is live right now — or, with nothing live,
     *             the session that ended most recently.
     *
     * `me` is the caller's own place in the SAME ranking the list is cut
     * from (so it can never disagree with it), and only exists for plain
     * students — staff are never ranked (see ComputeAttendanceStreaks).
     * It comes from the cached ranking, so asking for it costs one pass
     * over an in-memory array, not another streak computation.
     *
     * @return array<string, mixed>
     */
    public function __invoke(Student $viewer, string $scope): array
    {
        $session = null;
        $date = null;

        if ($scope === 'session') {
            $session = $this->resolveSession();
            $ranking = $session
                ? $this->buildStreakLeaderboard->ranking(throughSessionId: $session->id)
                : ['ranked' => [], 'total' => $this->buildStreakLeaderboard->ranking()['total']];
        } elseif ($scope === 'today') {
            $date = Carbon::now('Asia/Manila')->toDateString();
            $ranking = $this->buildStreakLeaderboard->ranking(onlyDate: $date);
        } else {
            $ranking = $this->buildStreakLeaderboard->ranking();
        }

        $me = null;

        if ($viewer->role === Role::Student && $viewer->status === Student::STATUS_ACTIVE && ($session !== null || $scope !== 'session')) {
            $me = $this->buildStreakLeaderboard->rankOf($ranking, $viewer->id);
        }

        return [
            'scope' => $scope,
            'date' => $date,
            'session' => $session ? [
                'session_id' => $session->id,
                'event_name' => $session->eventDay->event->name,
                'day_number' => $session->eventDay->day_number,
                'window_type' => $session->window_type->value,
                'check_type' => $session->check_type->value,
                'is_live' => $session->status === SessionStatus::Ongoing,
            ] : null,
            'total_students' => $ranking['total'],
            'ranked_count' => count($ranking['ranked']),
            'entries' => $this->buildStreakLeaderboard->hydrate(array_slice($ranking['ranked'], 0, self::LIMIT)),
            'me' => $me,
        ];
    }

    /**
     * The session the "Session" tab is about: the one ongoing right now
     * (most recently started if somehow several are), else the one that
     * ended most recently. Limited to the current semester because that's
     * the only timeline streaks are computed over.
     */
    private function resolveSession(): ?AttendanceSession
    {
        $currentSemesterId = CurrentSemester::id();

        $base = fn () => AttendanceSession::with('eventDay.event')
            ->when($currentSemesterId !== null, fn ($q) => $q->whereHas(
                'eventDay.event',
                fn ($event) => $event->where('semester_id', $currentSemesterId)->orWhereNull('semester_id')
            ));

        return $base()->where('status', SessionStatus::Ongoing->value)->orderByDesc('id')->first()
            ?? $base()->where('status', SessionStatus::Ended->value)->orderByDesc('ended_at')->orderByDesc('id')->first();
    }
}
