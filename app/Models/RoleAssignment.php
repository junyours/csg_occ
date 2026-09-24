<?php

namespace App\Models;

use App\Domain\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail (spec §4.4) — one row per role change, never
 * updated or deleted.
 */
class RoleAssignment extends Model
{
    protected $fillable = ['student_id', 'old_role', 'new_role', 'changed_by'];

    protected function casts(): array
    {
        return [
            'old_role' => Role::class,
            'new_role' => Role::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'changed_by');
    }
}
