<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventHasOngoingSessionException;
use App\Domain\Exceptions\SessionNotScheduledException;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class StartSession
{
    /**
     * Move a session scheduled → ongoing. This is a manual, human-triggered
     * transition (spec §7.2 already treats ending as manual because "real
     * events run behind schedule" — the same is true of starting: a
     * clock-based auto-start is unreliable on shared hosting with no
     * queue/cron worker, and unreliable per-device besides, since a
     * scanning phone's clock can be minutes off in either direction. So a
     * CSG Admin taps Start on the session card, same as End.
     *
     * Only one session per *event* may be ongoing at a time — e.g. Day 1
     * Morning Time Out can't be started while Day 1 Morning Time In (or
     * any other session under the same event) hasn't been ended yet. This
     * is scoped to the event, not globally: two different events can each
     * have their own session ongoing at the same time without conflict.
     *
     * @throws SessionNotScheduledException if the session is already
     *         ongoing or has already ended.
     * @throws EventAlreadyEndedException if the session's parent event has
     *         already been ended by CSG — see App\Application\Actions\Events\EndEvent.
     *         A session left `scheduled` when its event ended is never
     *         force-transitioned (nothing to finalize), but it also can't
     *         be started afterward: the event is over.
     * @throws EventHasOngoingSessionException if another session under the
     *         same event is already ongoing.
     */
    public function __invoke(AttendanceSession $session): AttendanceSession
    {
        return DB::transaction(function () use ($session) {
            // Lock the row so two concurrent "start" taps can't both pass
            // the status check — mirrors EndSession's use of lockForUpdate.
            $locked = AttendanceSession::whereKey($session->id)->lockForUpdate()->first();

            if ($locked->status !== SessionStatus::Scheduled) {
                throw new SessionNotScheduledException;
            }

            $eventId = $locked->eventDay->event_id;

            // Also lock the event row: two officers tapping "Start" on two
            // *different* scheduled sessions of the same event at the same
            // instant could otherwise both pass the "any ongoing sibling?"
            // check below before either UPDATE commits. Locking the event
            // row serializes every concurrent start attempt for the same
            // event through this one check, the same way locking the
            // session row above serializes concurrent starts of the same
            // session.
            $event = EventModel::whereKey($eventId)->lockForUpdate()->first();

            if ($event->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot start this session because its event has already been ended.'
                );
            }

            $anotherSessionOngoing = AttendanceSession::query()
                ->whereHas('eventDay', fn ($query) => $query->where('event_id', $eventId))
                ->where('id', '!=', $locked->id)
                ->where('status', SessionStatus::Ongoing)
                ->exists();

            if ($anotherSessionOngoing) {
                throw new EventHasOngoingSessionException;
            }

            $locked->update(['status' => SessionStatus::Ongoing]);

            return $locked;
        });
    }
}
