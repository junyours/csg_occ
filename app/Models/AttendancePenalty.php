<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendancePenalty extends Model
{
    protected $fillable = [
        'student_id', 'session_id', 'amount', 'reason', 'is_reversed', 'reversed_by',
        'reversal_reason', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_reversed' => 'boolean',
            'reversed_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class, 'session_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'reversed_by');
    }

    /**
     * The append-only audit trail for this penalty (see PenaltyReversal).
     * In practice this holds at most one row — reversal is one-way, so a
     * penalty can never be reversed twice — but it's a hasMany rather
     * than a hasOne so the audit record stays intact even if that
     * invariant ever changes.
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(PenaltyReversal::class, 'attendance_penalty_id');
    }
}
