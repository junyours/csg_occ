<?php

namespace App\Models;

use App\Domain\Enums\CheckType;
use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use App\Domain\ValueObjects\TimeWindow;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceSession extends Model
{
    protected $table = 'attendance_sessions';

    protected $fillable = [
        'event_day_id', 'window_type', 'check_type', 'start_time', 'end_time',
        'grace_minutes', 'penalty_late_amount', 'penalty_absent_amount', 'status', 'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'window_type' => WindowType::class,
            'check_type' => CheckType::class,
            'status' => SessionStatus::class,
            'penalty_late_amount' => 'decimal:2',
            'penalty_absent_amount' => 'decimal:2',
            // The precise instant EndSession closed this session — see
            // the 2026_09_16_090000_add_ended_at_to_attendance_sessions_table
            // migration and App\Models\Exclusion::excludedStudentIdsForSession
            // for why this (not just `status`) is what an event-scope
            // exclusion's forward-only cascade is evaluated against.
            'ended_at' => 'datetime',
        ];
    }

    /**
     * event-day-window-edit-delete-plan.md §4.3a bugfix: StoreSessionRequest
     * and UpdateSessionRequest both validate start_time/end_time as
     * `date_format:H:i` (no seconds — that's what a plain <input
     * type="time">/time picker actually submits), but the `time` DB
     * column stores back exactly whatever string it's given. Without
     * this, a session created straight from the API ends up with
     * '08:00' in the column while one created directly in a factory/
     * seeder (or from a value that already carries seconds) ends up
     * with '08:00:00' — an inconsistency that showed up as a real test
     * failure (UpdateSession returning '08:00' instead of '08:00:00')
     * and would just as easily bite anything downstream doing a plain
     * string comparison against these columns. Normalizing to H:i:s on
     * write makes every session's start_time/end_time the same shape
     * regardless of which caller set it or whether seconds were given.
     */
    protected function startTime(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? Carbon::parse($value)->format('H:i:s') : null,
        );
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? Carbon::parse($value)->format('H:i:s') : null,
        );
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'session_id');
    }

    public function window(): TimeWindow
    {
        return new TimeWindow(
            $this->combineWithDate($this->start_time),
            $this->combineWithDate($this->end_time),
            $this->grace_minutes,
        );
    }

    private function combineWithDate(string $time): Carbon
    {
        return Carbon::parse($this->eventDay->date->format('Y-m-d').' '.$time, 'Asia/Manila');
    }
}
