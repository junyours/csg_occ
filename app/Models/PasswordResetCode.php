<?php

namespace App\Models;

use App\Domain\Enums\PasswordResetCodeStatus;
use App\Domain\Enums\Role;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "forgot password" code. The student asks for one on the login screen;
 * it is never sent to them — an SC/CSG/System admin reads it off the
 * Password Reset Codes screen and gives it to the student in person.
 */
class PasswordResetCode extends Model
{
    public const CODE_LENGTH = 6;

    /** How long a code (and, separately, a verified reset session) lives. */
    public const TTL_MINUTES = 5;

    /** Wrong guesses allowed before the code is burned. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'student_id', 'code', 'attempts', 'expires_at', 'used_at',
        'reset_token_hash', 'reset_token_expires_at',
    ];

    protected $hidden = ['code', 'reset_token_hash'];

    protected function casts(): array
    {
        return [
            'code' => 'encrypted',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'reset_token_expires_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Saves attribute changes WITHOUT letting the database touch expires_at.
     *
     * On MySQL/MariaDB the first NOT NULL `timestamp` column of a table
     * silently gets "ON UPDATE CURRENT_TIMESTAMP" — so an innocent
     * `attempts++` used to rewrite expires_at to "now" in the DB session's
     * timezone (+8h ahead of UTC on a Manila server), which is how a 5-minute
     * code ended up showing 476 minutes. The migration that turns the column
     * into a plain DATETIME is the real fix; naming the column in the UPDATE
     * keeps this safe even if that migration hasn't run yet.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateKeepingExpiry(array $attributes): void
    {
        static::query()->whereKey($this->getKey())->update($attributes + [
            'expires_at' => $this->expires_at,
            'updated_at' => now(),
        ]);

        $this->forceFill($attributes)->syncOriginal();
    }

    public function status(?CarbonInterface $now = null): PasswordResetCodeStatus
    {
        $now ??= now();

        if ($this->used_at !== null) {
            return PasswordResetCodeStatus::Used;
        }

        if ($this->expires_at->lessThanOrEqualTo($now)) {
            return PasswordResetCodeStatus::Expired;
        }

        if ($this->attempts >= self::MAX_ATTEMPTS) {
            return PasswordResetCodeStatus::Locked;
        }

        return PasswordResetCodeStatus::Pending;
    }

    /**
     * The codes this admin is allowed to see (and therefore to clear).
     *
     * Visibility is by role rank AND department, mirroring who is allowed to
     * touch whom in StudentPolicy::assignRole:
     *  - System Admin: every code.
     *  - CSG Admin: students, officers and SC admins (any department) —
     *    never a CSG Admin or System Admin.
     *  - SC Admin: students and officers whose department is the one this
     *    SC Admin administers — never another SC Admin or anyone above.
     *
     * The rank rule is the important half. Without it an SC Admin could
     * trigger a reset for a CSG/System admin in their own department, read
     * the code off the screen, and walk into that account.
     */
    public function scopeVisibleTo(Builder $query, Student $actor): Builder
    {
        return match ($actor->role) {
            Role::SystemAdmin => $query,

            Role::CsgAdmin => $query->whereHas(
                'student',
                fn (Builder $student) => $student->whereIn('role', [
                    Role::ScAdmin->value, Role::Officer->value, Role::Student->value,
                ]),
            ),

            // An SC Admin with no administered department must see nothing.
            // Guarded explicitly: where('department_id', null) is turned by
            // Eloquent into "department_id IS NULL", which would hand them
            // every department-less student.
            Role::ScAdmin => $actor->sc_admin_department_id === null
                ? $query->whereRaw('1 = 0')
                : $query->whereHas(
                    'student',
                    fn (Builder $student) => $student
                        ->where('department_id', $actor->sc_admin_department_id)
                        ->whereIn('role', [Role::Officer->value, Role::Student->value]),
                ),

            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Rows with nothing left to do: expired without ever being used, or used
     * and either finished (password changed → token nulled) or abandoned
     * (reset token ran out). A used row whose reset token is still live is
     * deliberately NOT purgeable — the student is mid-way through setting
     * their new password and the row is what authorizes it.
     */
    public function scopePurgeable(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query->where(function (Builder $outer) use ($now) {
            $outer->where(function (Builder $expired) use ($now) {
                $expired->whereNull('used_at')->where('expires_at', '<=', $now);
            })->orWhere(function (Builder $used) use ($now) {
                $used->whereNotNull('used_at')->where(function (Builder $finished) use ($now) {
                    $finished->whereNull('reset_token_hash')
                        ->orWhere('reset_token_expires_at', '<=', $now);
                });
            });
        });
    }
}
