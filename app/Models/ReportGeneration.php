<?php

namespace App\Models;

use App\Domain\Enums\ReportGenerationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportGeneration extends Model
{
    protected $fillable = [
        'event_id', 'event_ids', 'format', 'department_id', 'major', 'year_level', 'section',
        'status', 'total_steps', 'processed_steps',
        'file_disk', 'file_path', 'file_name', 'error_message', 'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportGenerationStatus::class,
            'total_steps' => 'integer',
            'processed_steps' => 'integer',
            'event_ids' => 'array',
        ];
    }

    /**
     * True for a master (multi-event) generation — event_ids holds more
     * than the one event event_id already points at (see the migration
     * that added event_ids for why event_id is still set on these rows).
     */
    public function isMaster(): bool
    {
        return is_array($this->event_ids) && count($this->event_ids) > 1;
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModel::class, 'event_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'requested_by');
    }

    /**
     * 0-100. While total_steps is still 0 (the very first moment after
     * creation, before StartRosterReportGeneration's estimate lands or
     * for a zero-group filter), this reads as 0% unless already marked
     * Completed/Failed — never divides by zero.
     */
    public function progressPercent(): int
    {
        if ($this->status === ReportGenerationStatus::Completed) {
            return 100;
        }

        if ($this->total_steps <= 0) {
            return 0;
        }

        return (int) min(100, round(($this->processed_steps / $this->total_steps) * 100));
    }
}
