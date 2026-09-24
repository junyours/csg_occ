<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\SessionStatus;
use App\Domain\Exceptions\EventAlreadyEndedException;
use App\Domain\Exceptions\EventHasActiveExclusionsException;
use App\Domain\Exceptions\EventHasReportsException;
use App\Domain\Exceptions\EventHasStartedSessionException;
use App\Models\AttendanceSession;
use App\Models\EventModel;
use App\Models\Exclusion;
use App\Models\ReportGeneration;
use Illuminate\Support\Facades\DB;

final class DeleteEvent
{
    /**
     * Deletes a whole Event — for the "I created this by mistake" case,
     * not a general-purpose way to erase an event's history. Guarded
     * more strictly than DeleteEventDay because there's no cascade to
     * fall back on for exclusions or report generations:
     *
     *  1. Event already ended → refused (same §4.1a "fully locked" rule
     *     the day/session actions use).
     *  2. Any session under any day has started or ended → refused. A
     *     `Scheduled`-only event can't have attendance_records or
     *     attendance_penalties either, so there's nothing under it that
     *     deleting would actually destroy.
     *  3. Any *active* Exclusion targeting this event → refused. This is
     *     the resolvable case: reverse the exclusion first (Manage
     *     Exclusions → remove), then retry the delete. A `removed`
     *     exclusion does NOT block — once it's been reversed it's no
     *     longer describing a real restriction, and the whole point of
     *     deleting the event is that it never actually ran, so there's
     *     no "Excluded" history under it worth preserving (contrast
     *     with RemoveExclusion, which preserves history for an event
     *     that's still going or has already run for real).
     *  4. Any ReportGeneration row references this event → refused. A
     *     generated report is a real work product (a file someone may
     *     have already downloaded), so this one is never auto-cleaned —
     *     it isn't the "accidentally excluded a student" case this
     *     action is built to unblock.
     *
     * Once all four pass: any lingering *removed* Exclusion rows for
     * this event are hard-deleted here (they cleared guard #3 precisely
     * because they no longer matter, and `exclusions.event_id` has no
     * cascade/nullOnDelete, so they'd otherwise fail the actual event
     * delete with a raw DB foreign-key error) — then the event delete
     * itself is left to the DB's own `cascadeOnDelete()` on
     * event_days.event_id and event_departments.event_id, safe here
     * specifically because guard #2 already confirmed there's no session
     * history anywhere under this event to lose.
     *
     * @throws EventAlreadyEndedException
     * @throws EventHasStartedSessionException
     * @throws EventHasActiveExclusionsException
     * @throws EventHasReportsException
     */
    public function __invoke(EventModel $event): void
    {
        DB::transaction(function () use ($event) {
            $locked = EventModel::whereKey($event->id)->lockForUpdate()->first();

            if ($locked->status === EventStatus::Ended) {
                throw new EventAlreadyEndedException(
                    'Cannot delete this event because it has already been ended.'
                );
            }

            $hasStartedSession = AttendanceSession::query()
                ->whereHas('eventDay', fn ($query) => $query->where('event_id', $locked->id))
                ->whereIn('status', [SessionStatus::Ongoing->value, SessionStatus::Ended->value])
                ->lockForUpdate()
                ->exists();

            if ($hasStartedSession) {
                throw new EventHasStartedSessionException(
                    'Cannot delete this event because at least one of its sessions has already started or ended.'
                );
            }

            $hasActiveExclusions = Exclusion::query()
                ->where('event_id', $locked->id)
                ->where('status', ExclusionStatus::Active->value)
                ->lockForUpdate()
                ->exists();

            if ($hasActiveExclusions) {
                throw new EventHasActiveExclusionsException(
                    'Cannot delete this event because it still has active exclusions. Remove them from Manage Exclusions first, then delete the event.'
                );
            }

            $hasReports = ReportGeneration::query()->where('event_id', $locked->id)->exists();

            if ($hasReports) {
                throw new EventHasReportsException(
                    'Cannot delete this event because a report has already been generated for it.'
                );
            }

            // Everything left referencing this event via event_id is a
            // removed (never-active-again) Exclusion — safe to hard-delete
            // as part of the event's own deletion, since the FK has no
            // cascade of its own.
            Exclusion::query()->where('event_id', $locked->id)->delete();

            $locked->delete();
        }, attempts: 3);
    }
}

