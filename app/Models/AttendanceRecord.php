<?php

namespace App\Models;

use App\Domain\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'session_id', 'student_id', 'scanned_at', 'status', 'scanned_by',
        'department_id', 'major', 'section', 'year_level', 'semester_id', 'auto_present',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'scanned_at' => 'datetime',
            'auto_present' => 'boolean',
        ];
    }

    /** Enrollment context frozen onto a record; semester is the *event's*. */
    public static function snapshotFor(Student $student, ?int $semesterId): array
    {
        return [
            'department_id' => $student->department_id,
            'major' => $student->major,
            'section' => $student->section,
            'year_level' => $student->year_level,
            'semester_id' => $semesterId,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Who scanned this record. Points at the students table like
     * `student()` does — staff accounts (Officer/SC Admin/CSG Admin/
     * System Admin) live in that same table as role-flagged rows rather
     * than a separate staff table (see Student::isAttendanceEligibleForEvent
     * for the same assumption elsewhere), so "the scanning officer" and
     * "the attendee" are both Student models, just distinguished by role.
     */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'scanned_by');
    }

    /**
     * Present/missed/late records inherit their event scope transitively
     * through session -> event_day -> event (no denormalized event_id
     * column here — see the events/semesters migration notes). This scope
     * is the query-time equivalent, letting dashboards fetch "every
     * record for event X" without hand-rolling the join each time.
     *
     * @param  Builder<AttendanceRecord>  $query
     * @return Builder<AttendanceRecord>
     */
    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->whereHas('session.eventDay', function (Builder $eventDayQuery) use ($eventId) {
            $eventDayQuery->where('event_id', $eventId);
        });
    }
}
