<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\SessionAlreadyStartedException;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class UpdateSession
{
    /**
     * event-day-window-edit-delete-plan.md §4.3a: editing a single check
     * (start_time/end_time/grace_minutes/penalty amounts, and — per
     * Bug #13 — window_type/check_type too, since a Scheduled session's
     * (event_day_id, window_type, check_type) triplet isn't otherwise
     * locked in) is only allowed while that one session is still
     * `Scheduled` — each check has its own independent time range, so
     * this is deliberately a per-session operation rather than a
     * per-window one (unlike delete, which also offers a whole-window
     * convenience). §4.1a's event-ended lock is checked first, same as
     * every other action here.
     *
     * @param  array{
     *     window_type?: string,
     *     check_type?: string,
     *     start_time?: string,
     *     end_time?: string,
     *     grace_minutes?: int|null,
     *     penalty_late_amount?: float|null,
     *     penalty_absent_amount?: float|null,
     * }  $data
     *
     * @throws EventAlreadyEndedException
     * @throws SessionAlreadyStartedException
     */
    public function __invoke(AttendanceSession $session, array $data): AttendanceSession
    {
        return DB::transaction(function () use ($session, $data) {
            // Lock the session row first — mirrors StartSession's own
            // ordering (the row the action is fundamentally about,
            // before the row it merely needs to check).
            $locked = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            $event = EventModel::whereKey($locked->eventDay->event_id)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot update this session because its event has already been ended.'
                );
            }

            if ($locked->status !== SessionStatus::Scheduled) {
                throw new SessionAlreadyStartedException;
            }

            $locked->update(array_intersect_key($data, array_flip([
                'window_type', 'check_type', 'start_time', 'end_time', 'grace_minutes',
                'penalty_late_amount', 'penalty_absent_amount',
            ])));

            return $locked->fresh(['eventDay']);
        });
    }
}
