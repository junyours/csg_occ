<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (mirrors RoleAssignment) — one row per penalty
 * reversal, never updated or deleted. AttendancePenalty's own
 * is_reversed/reversed_by/reversed_at columns are the fast, mutable
 * read path that reports and history screens already query; this table
 * is the durable "who excused what, when, and why" record that survives
 * independently of that row, for future audit-log export (spec §11).
 */
class PenaltyReversal extends Model
{
    protected $fillable = ['attendance_penalty_id', 'student_id', 'reversed_by', 'reason'];

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(AttendancePenalty::class, 'attendance_penalty_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'reversed_by');
    }
}
