<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Sessions\EndSession;
use App\Domain\Enums\EventStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\SessionAlreadyEndedException;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use Illuminate\Support\Facades\DB;

final class EndEvent
{
    public function __construct(private readonly EndSession $endSession) {}

    /**
     * End an event: a CSG-only, manual action (mirrors EndSession's own
     * reasoning — real events don't wrap up on a clock, a human decides).
     *
     * Ending an event does two things atomically:
     *
     *  1. Force-ends every currently `ongoing` session under it, running
     *     each through the exact same EndSession action a CSG Admin would
     *     trigger by tapping "End" on that session individually — so
     *     late/absent finalization and penalty application happen exactly
     *     once, the normal way, for every open window. `scheduled`
     *     sessions (never started) are left alone: there's nothing to
     *     finalize for a session that never opened, and no attendance
     *     records to reconcile.
     *  2. Marks the event itself `ended`.
     *
     * Without this cascade, an ended event could still have an `ongoing`
     * session sitting open with no one left to close it — students could
     * keep scanning into a session that belongs to an event CSG has
     * already declared over.
     *
     * This is the inverse of the bug this feature fixes: previously the
     * *event's* apparent state was inferred from its sessions (any
     * session ongoing → event looks active), so ending the last ongoing
     * session made a still-unfinished event look done. Now the event
     * carries its own status, set only here — ending sessions never
     * implicitly ends the event, and ending the event explicitly ends
     * whatever sessions are still open.
     *
     * @return array{event_id: int, sessions_ended: int}
     *
     * @throws EventAlreadyEndedException
     */
    public function __invoke(EventModel $event): array
    {
        return DB::transaction(function () use ($event) {
            // Lock the row so two concurrent "end event" requests can't
            // both pass the status check and double-run this action —
            // mirrors EndSession's use of lockForUpdate.
            $locked = EventModel::whereKey($event->id)->lockForUpdate()->first();

            if ($locked->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException;
            }

            $ongoingSessionIds = AttendanceSession::query()
                ->whereHas('eventDay', fn ($query) => $query->where('event_id', $locked->id))
                ->where('status', SessionStatus::Ongoing)
                ->pluck('id');

            $sessionsEnded = 0;

            foreach ($ongoingSessionIds as $sessionId) {
                $session = AttendanceSession::find($sessionId);

                if (! $session) {
                    continue;
                }

                try {
                    ($this->endSession)($session);
                    $sessionsEnded++;
                } catch (SessionAlreadyEndedException) {
                    // Already closed by someone else between the pluck
                    // above and this loop reaching it — not this action's
                    // problem, the session is in the state we wanted.
                }
            }

            $locked->update(['status' => EventStatus::Ended]);

            return [
                'event_id' => $locked->id,
                'sessions_ended' => $sessionsEnded,
            ];
        });
    }
}
