<?php

namespace App\Application\Actions\Events;

use App\Domain\Enums\EventStatus;
use App\Domain\Exceptions\EventNotEndedException;
use App\Models\EventModel;
use App\Models\ReportGeneration;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ForceDeleteEvent
{
    /**
     * System Admin override for removing an ENDED event (e.g. a client's
     * test event) that DeleteEvent refuses by design. Hard-deletes the
     * event and everything that belongs to it: days, sessions, attendance
     * records (present/late/missed/excluded), penalties and their
     * reversals, attendance-record reversals, exclusions, generated
     * reports (and their files). Students, photos, names, roles and every
     * other event are untouched, and dashboard/penalty totals drop by
     * exactly this event's share.
     *
     * Safety: ended events only, and the caller must type the event's
     * exact name. Multi-event master reports that include this event are
     * kept (this event is removed from their list); reports made for this
     * event alone are deleted.
     *
     * @return array{event_id: int, attendance_records: int, penalties: int}
     *
     * @throws EventNotEndedException
     * @throws ValidationException
     */
    public function __invoke(EventModel $event, string $confirmName, Student $actor): array
    {
        $reportFiles = [];

        $result = DB::transaction(function () use ($event, $confirmName, $actor, &$reportFiles) {
            $locked = EventModel::whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== EventStatus::Ended) {
                throw new EventNotEndedException;
            }

            if (trim($confirmName) !== $locked->name) {
                throw ValidationException::withMessages([
                    'confirm_name' => 'The name you typed does not match this event.',
                ]);
            }

            $eventId = $locked->id;

            $sessionIds = DB::table('attendance_sessions')
                ->join('event_days', 'event_days.id', '=', 'attendance_sessions.event_day_id')
                ->where('event_days.event_id', $eventId)
                ->pluck('attendance_sessions.id')->all();

            $penaltyIds = $sessionIds === []
                ? []
                : DB::table('attendance_penalties')->whereIn('session_id', $sessionIds)->pluck('id')->all();

            $recordCount = 0;

            if ($penaltyIds !== []) {
                DB::table('penalty_reversals')->whereIn('attendance_penalty_id', $penaltyIds)->delete();
                DB::table('attendance_penalties')->whereIn('id', $penaltyIds)->delete();
            }

            if ($sessionIds !== []) {
                DB::table('attendance_record_reversals')->whereIn('session_id', $sessionIds)->delete();
                $recordCount = DB::table('attendance_records')->whereIn('session_id', $sessionIds)->delete();
            }

            DB::table('exclusions')->where('event_id', $eventId)->delete();

            // Master (multi-event) reports that include this event are kept:
            // drop this event from their list and, if the row pointed at it
            // through event_id, repoint to another event still in the list.
            // The already-generated file is untouched.
            ReportGeneration::whereNotNull('event_ids')->get()
                ->filter(fn ($r) => count($r->event_ids ?? []) > 1 && in_array($eventId, $r->event_ids, true))
                ->each(function ($r) use ($eventId) {
                    $remaining = array_values(array_diff($r->event_ids, [$eventId]));
                    $r->update([
                        'event_ids' => $remaining,
                        'event_id' => $r->event_id === $eventId ? $remaining[0] : $r->event_id,
                    ]);
                });

            $reportFiles = ReportGeneration::where('event_id', $eventId)
                ->whereNotNull('file_path')
                ->get(['file_disk', 'file_path'])
                ->map(fn ($r) => [$r->file_disk, $r->file_path])
                ->all();
            DB::table('report_generations')->where('event_id', $eventId)->delete();

            DB::table('students')->where('officer_event_id', $eventId)->update(['officer_event_id' => null]);

            if ($sessionIds !== []) {
                DB::table('attendance_sessions')->whereIn('id', $sessionIds)->delete();
            }
            DB::table('event_days')->where('event_id', $eventId)->delete();
            DB::table('event_departments')->where('event_id', $eventId)->delete();
            DB::table('events')->where('id', $eventId)->delete();

            Log::warning('Event force-deleted', [
                'event_id' => $eventId,
                'event_name' => $locked->name,
                'by_student_id' => $actor->id,
                'attendance_records' => $recordCount,
                'penalties' => count($penaltyIds),
            ]);

            return [
                'event_id' => $eventId,
                'attendance_records' => $recordCount,
                'penalties' => count($penaltyIds),
            ];
        }, attempts: 3);

        foreach ($reportFiles as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        return $result;
    }
}
