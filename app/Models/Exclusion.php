<?php

namespace App\Models;

use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\ExclusionStatus;
use App\Domain\Enums\WindowType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exclusion extends Model
{
    protected $fillable = [
        'student_id', 'event_id', 'scope', 'event_day_id', 'window_type',
        'reason', 'status', 'batch_id', 'created_by', 'removed_by', 'removed_at',
    ];

    protected function casts(): array
    {
        return [
            'scope' => ExclusionScope::class,
            'window_type' => WindowType::class,
            'status' => ExclusionStatus::class,
            'removed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModel::class, 'event_id');
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class, 'event_day_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'created_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'removed_by');
    }

    /**
     * student-exclusion-feature-plan.md §3 "Effective exclusion logic" —
     * a student is excluded for a given session if any ACTIVE exclusion
     * matches it:
     *
     *  - scope=window, same event_day_id + window_type (covers both the
     *    time-in and time-out AttendanceSession rows of that window), or
     *  - scope=day, same event_day_id (every window under that day), or
     *  - scope=event, AND this specific session had not already ended
     *    at the moment the event-scope exclusion was created (§6a's
     *    "not retroactive" resolution).
     *
     * The event-scope condition is evaluated per session (not per day)
     * using AttendanceSession.ended_at: a session with ended_at still
     * null was open (or didn't exist) when the exclusion was created and
     * is covered; a session whose ended_at is *after* the exclusion's
     * created_at was still open at that moment and is covered (this is
     * the "ongoing day: excluded going forward" row in §6a's table); a
     * session whose ended_at is at/before the exclusion's created_at had
     * already closed out and is left untouched, preserving its
     * Present/Absent/Late history.
     *
     * This method is deliberately NOT what an already-ended session's
     * report reads from. Because it only ever matches status=active
     * rows, calling it after an exclusion has been removed would make
     * an already-ended, already-"Excluded" session silently look
     * un-excluded again — rewriting history. EndSession calls this once,
     * at the moment a session closes, and persists the outcome as a real
     * `excluded` AttendanceRecord (see EndSession::markMissingRecords);
     * every report thereafter reads that permanent row instead. This
     * method itself stays live/on-demand for everything that still needs
     * "is this excluded right now": ScanAttendance (blocking a live
     * scan), EndSession (deciding what to freeze in at close time), and
     * the report-building actions (BuildSessionReport,
     * BuildEventRosterReport, BuildMasterReport) — but only as a
     * fallback for a session that hasn't ended yet and therefore has no
     * permanent row for a given student. Each of those already prefers
     * an existing AttendanceRecord over this method's result — see their
     * own docblocks.
     *
     * Because this is computed live against event_id rather than a
     * frozen snapshot, a brand-new day/session created after the
     * event-scope exclusion already exists is automatically covered too
     * (§6a point 4) — its ended_at is null until it's actually ended.
     *
     * @return array<int, int>
     */
    public static function excludedStudentIdsForSession(AttendanceSession $session): array
    {
        $eventDayId = $session->event_day_id;
        $windowType = $session->window_type->value;
        $eventId = $session->eventDay->event_id;
        $sessionEndedAt = $session->ended_at;

        return static::query()
            ->where('event_id', $eventId)
            ->where('status', ExclusionStatus::Active->value)
            ->where(function ($query) use ($eventDayId, $windowType, $sessionEndedAt) {
                $query->where(function ($q) use ($eventDayId, $windowType) {
                    $q->where('scope', ExclusionScope::Window->value)
                        ->where('event_day_id', $eventDayId)
                        ->where('window_type', $windowType);
                })
                    ->orWhere(function ($q) use ($eventDayId) {
                        $q->where('scope', ExclusionScope::Day->value)
                            ->where('event_day_id', $eventDayId);
                    })
                    ->orWhere(function ($q) use ($sessionEndedAt) {
                        $q->where('scope', ExclusionScope::Event->value)
                            ->when(
                                $sessionEndedAt !== null,
                                fn ($eventQuery) => $eventQuery->where('created_at', '<', $sessionEndedAt),
                                fn ($eventQuery) => $eventQuery, // session never ended: always covered
                            );
                    });
            })
            ->pluck('student_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether a given student already has an ACTIVE exclusion for the
     * exact same scope (student-exclusion-feature-plan.md §5 step 4).
     *
     * @param  array{scope: ExclusionScope, event_day_id?: ?int, window_type?: ?WindowType}  $target
     */
    public static function hasActiveDuplicate(int $studentId, int $eventId, array $target): bool
    {
        return static::query()
            ->where('student_id', $studentId)
            ->where('event_id', $eventId)
            ->where('status', ExclusionStatus::Active->value)
            ->where('scope', $target['scope']->value)
            ->where('event_day_id', $target['event_day_id'] ?? null)
            ->where('window_type', isset($target['window_type']) ? $target['window_type']->value : null)
            ->exists();
    }
}
