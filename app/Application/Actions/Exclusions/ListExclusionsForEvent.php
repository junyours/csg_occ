<?php

namespace App\Application\Actions\Exclusions;

use App\Domain\Enums\EventStatus;
use App\Domain\Enums\ExclusionScope;
use App\Models\EventModel;
use App\Models\Exclusion;

/**
 * Backs the Manage Exclusions screen (student-exclusion-feature-plan.md
 * §4B): every exclusion ever recorded for this event — active and
 * removed alike, since removed rows are still part of the audit trail
 * (§4's "added by, added at, scope, batch reference") — annotated with
 * whether its own target scope has already ended, so the frontend can
 * grey out "Remove" for a row RemoveExclusion would reject anyway
 * without needing to duplicate that rule client-side.
 *
 * Returned flat (not pre-grouped): the plan's Event-wide → Day → Window
 * grouping is a display concern the frontend applies itself from
 * day_number/window_type, keeping this action a plain data source.
 */
final class ListExclusionsForEvent
{
    /**
     * @return array<int, array{
     *     id: int, scope: string, status: string, reason: string,
     *     event_day_id: ?int, day_number: ?int, window_type: ?string,
     *     student_id: int, student_number: string, student_name: string,
     *     created_by: string, created_at: string,
     *     removed_by: ?string, removed_at: ?string,
     *     batch_id: ?string, target_has_ended: bool,
     * }>
     */
    public function __invoke(EventModel $event): array
    {
        $exclusions = Exclusion::where('event_id', $event->id)
            ->with(['student', 'eventDay', 'createdBy', 'removedBy'])
            ->orderByDesc('created_at')
            ->get();

        return $exclusions->map(function (Exclusion $exclusion) use ($event) {
            return [
                'id' => $exclusion->id,
                'scope' => $exclusion->scope->value,
                'status' => $exclusion->status->value,
                'reason' => $exclusion->reason,
                'event_day_id' => $exclusion->event_day_id,
                'day_number' => $exclusion->eventDay?->day_number,
                'window_type' => $exclusion->window_type?->value,
                'student_id' => $exclusion->student_id,
                'student_number' => $exclusion->student->student_number,
                'student_name' => trim($exclusion->student->first_name.' '.$exclusion->student->last_name),
                'created_by' => trim($exclusion->createdBy->first_name.' '.$exclusion->createdBy->last_name),
                'created_at' => $exclusion->created_at->toIso8601String(),
                'removed_by' => $exclusion->removedBy
                    ? trim($exclusion->removedBy->first_name.' '.$exclusion->removedBy->last_name)
                    : null,
                'removed_at' => $exclusion->removed_at?->toIso8601String(),
                'batch_id' => $exclusion->batch_id,
                'target_has_ended' => $this->targetHasEnded($exclusion, $event),
            ];
        })->values()->all();
    }

    private function targetHasEnded(Exclusion $exclusion, EventModel $event): bool
    {
        return match ($exclusion->scope) {
            ExclusionScope::Event => $event->status === EventStatus::Ended,
            ExclusionScope::Day => $exclusion->eventDay?->hasEnded() ?? false,
            ExclusionScope::Window => $exclusion->window_type
                && ($exclusion->eventDay?->windowHasEnded($exclusion->window_type) ?? false),
        };
    }
}
