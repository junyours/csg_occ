<?php

namespace App\Application\Actions\Exclusions;

use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\ExclusionAlreadyRemovedException;
use App\Domain\Exceptions\ExclusionNotRemovableException;
use App\Models\Exclusion;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the original MVP's hard ->delete() (student-exclusion-
 * feature-plan.md §6): removing an exclusion is a soft state change to
 * `status = removed`, never a row delete, so history (which sessions
 * already read "Excluded" while it was active) survives untouched — see
 * Exclusion::excludedStudentIdsForSession, which only ever matches
 * status=active rows, and the report-building actions, which read from
 * AttendanceRecord/AttendancePenalty rather than from Exclusion for
 * anything already finalized.
 *
 * §6's rule: an exclusion can only be removed while its own target
 * scope hasn't ended yet.
 *  - Event scope: removable until the event itself ends.
 *  - Day scope: removable until that specific day ends.
 *  - Window scope: removable until that specific day+window ends.
 *
 * Once that scope ends, the exclusion is locked — removing it would
 * silently flip already-reported "Excluded" sessions back to normal
 * tracking retroactively, which is exactly the history-rewriting §6a
 * was written to prevent. (An event-scope exclusion removed *before*
 * the event ends still correctly leaves already-ended days/windows
 * reading "Excluded" — see the model docblock — this restriction is
 * only about the exclusion row itself.)
 */
final class RemoveExclusion
{
    /**
     * @throws ExclusionAlreadyRemovedException
     * @throws ExclusionNotRemovableException
     */
    public function __invoke(Exclusion $exclusion, Student $removedBy): Exclusion
    {
        return DB::transaction(function () use ($exclusion, $removedBy) {
            // Lock the exclusion row itself, and the event row it targets,
            // so a concurrent "end event/day" and this removal can't race
            // past each other's checks (same reasoning as CreateExclusion).
            $locked = Exclusion::whereKey($exclusion->id)->lockForUpdate()->first();

            if ($locked->status === ExclusionStatus::Removed) {
                throw new ExclusionAlreadyRemovedException;
            }

            $event = $locked->event()->lockForUpdate()->first();

            if ($this->targetHasEnded($locked, $event->status)) {
                throw new ExclusionNotRemovableException;
            }

            $locked->update([
                'status' => ExclusionStatus::Removed,
                'removed_by' => $removedBy->id,
                'removed_at' => now(),
            ]);

            return $locked->fresh(['student', 'event', 'eventDay', 'createdBy', 'removedBy']);
        });
    }

    private function targetHasEnded(Exclusion $exclusion, EventStatus $eventStatus): bool
    {
        return match ($exclusion->scope) {
            ExclusionScope::Event => $eventStatus === EventStatus::Ended,
            ExclusionScope::Day => $exclusion->eventDay->hasEnded(forUpdate: true),
            ExclusionScope::Window => $exclusion->eventDay->windowHasEnded($exclusion->window_type, forUpdate: true),
        };
    }
}
