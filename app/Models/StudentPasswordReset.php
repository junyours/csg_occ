<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (mirrors PenaltyReversal) — one row per admin
 * "reset to default password", never updated or deleted.
 */
class StudentPasswordReset extends Model
{
    protected $fillable = ['student_id', 'reset_by'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function resetBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'reset_by');
    }
}
