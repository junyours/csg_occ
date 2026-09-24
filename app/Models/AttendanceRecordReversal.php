<?php

namespace App\Models;

use App\Domain\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (mirrors PenaltyReversal) — one row per
 * reversed scan, never updated or deleted. Unlike AttendancePenalty,
 * AttendanceRecord itself is deleted on reversal rather than flagged (see
 * ReverseAttendanceRecord for why), so this table is the *only* place a
 * wrongly-recorded scan is still readable afterwards: who was marked,
 * what they were marked as, who scanned it, who undid it, and why.
 */
class AttendanceRecordReversal extends Model
{
    protected $fillable = [
        'session_id', 'student_id', 'status', 'scanned_at', 'scanned_by', 'reversed_by', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'scanned_at' => 'datetime',
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

    /** Whoever made the original (wrong) scan — same nullable-staff shape as AttendanceRecord::scannedBy. */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'scanned_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'reversed_by');
    }
}
