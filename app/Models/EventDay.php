<?php

namespace App\Models;

use App\Domain\Enums\SessionStatus;
use App\Domain\Enums\WindowType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventDay extends Model
{
    protected $fillable = ['event_id', 'date', 'day_number'];

    protected function casts(): array
    {
        // Explicit 'Y-m-d' format (rather than the bare 'date' cast) so
        // this column both stores and serializes as a plain date string.
        // The bare 'date' cast still persists a full 'Y-m-d H:i:s'
        // string on save (it only truncates time on the PHP-side
        // accessor), which silently broke the (event_id, date)
        // uniqueness check in UpdateEventDayRequest/StoreEventDayRequest
        // — two different-looking dates could never collide because the
        // stored values always carried a midnight timestamp that never
        // matched the plain 'Y-m-d' string being validated against — and
        // it serialized to JSON as an ISO datetime
        // ('2026-11-15T00:00:00.000000Z') instead of '2026-11-15'.
        return ['date' => 'date:Y-m-d'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventModel::class, 'event_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    /**
     * There's no separate `status` column on event_days (see the
     * exclusions/attendance_sessions migration notes) — a day's own
     * upcoming/ongoing/ended state (student-exclusion-feature-plan.md
     * §3) is derived from its sessions instead of stored redundantly. A
     * day counts as "ended" once it has at least one scheduled session
     * and every session under it (every window, every check) has ended.
     * A day with zero sessions yet is never "ended" — there's nothing to
     * have finished, and it also can't be excluded at all yet (see rule
     * 3: schedule must exist first) so callers checking hasEnded() as a
     * gate before allowing an exclusion are already covered by that
     * separate existence check.
     *
     * @param  bool  $forUpdate  student-exclusion-feature-plan.md §6a
     *                           point 2: the ended/active check must be
     *                           atomic at commit time, not just read
     *                           optimistically. Pass true from inside a
     *                           DB transaction (CreateExclusion,
     *                           RemoveExclusion) to lock every underlying
     *                           attendance_sessions row this reads before
     *                           evaluating it — the exact rows EndSession
     *                           itself locks via `lockForUpdate()` when
     *                           it flips one to Ended — so a concurrent
     *                           "end this session" and "exclude from this
     *                           day" can never both read a stale
     *                           not-ended status past each other. Report-
     *                           building callers outside a transaction
     *                           should leave this false.
     */
    public function hasEnded(bool $forUpdate = false): bool
    {
        $query = $this->sessions();

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        return $query->exists()
            && ! $this->sessions()
                ->when($forUpdate, fn ($q) => $q->lockForUpdate())
                ->where('status', '!=', SessionStatus::Ended->value)
                ->exists();
    }

    /**
     * Same derivation as hasEnded(), narrowed to one window_type
     * ("Day 2 Morning") — a window is ended once every check
     * (time-in/time-out) under that day+window_type has ended.
     *
     * @param  bool  $forUpdate  See hasEnded()'s docblock.
     */
    public function windowHasEnded(WindowType $windowType, bool $forUpdate = false): bool
    {
        return $this->sessions()
            ->where('window_type', $windowType->value)
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())
            ->exists()
            && ! $this->sessions()
                ->where('window_type', $windowType->value)
                ->when($forUpdate, fn ($q) => $q->lockForUpdate())
                ->where('status', '!=', SessionStatus::Ended->value)
                ->exists();
    }

    /**
     * Whether this day currently has any session at all for the given
     * window_type — student-exclusion-feature-plan.md §2 rule 3 / §5:
     * CSG can only target a Day/Window that already exists on the event.
     */
    public function hasWindow(WindowType $windowType): bool
    {
        return $this->sessions()->where('window_type', $windowType->value)->exists();
    }

    /**
     * event-day-window-edit-delete-plan.md §4.2 — Bug #1: hasEnded() only
     * detects "has fully finished", which is the wrong question for the
     * edit/delete guards. Editing this day's date, or deleting the day
     * outright, must be refused the moment *anything* under it has
     * started (Ongoing) or finished (Ended) — not just once every
     * session has finished. A day with zero sessions passes this check
     * (nothing has started), matching §4.2's "or zero sessions exist"
     * delete rule.
     *
     * @param  bool  $forUpdate  Same contract as hasEnded()/windowHasEnded():
     *                           pass true from inside a DB::transaction()
     *                           to lock every underlying session row this
     *                           reads before evaluating it, so a
     *                           concurrent StartSession/EndSession can't
     *                           race past this check.
     */
    public function hasAnyStartedOrEndedSession(bool $forUpdate = false): bool
    {
        return $this->sessions()
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())
            ->whereIn('status', [SessionStatus::Ongoing->value, SessionStatus::Ended->value])
            ->exists();
    }

    /**
     * Same derivation as hasAnyStartedOrEndedSession(), narrowed to one
     * window_type — the guard for deleting a whole window (§4.3b): the
     * delete must be refused whole the moment even one check (time-in or
     * time-out) under that window has started or ended, not just once
     * every check has fully ended (windowHasEnded()'s question).
     *
     * @param  bool  $forUpdate  See hasAnyStartedOrEndedSession()'s docblock.
     */
    public function windowHasAnyStartedOrEndedSession(WindowType $windowType, bool $forUpdate = false): bool
    {
        return $this->sessions()
            ->where('window_type', $windowType->value)
            ->when($forUpdate, fn ($q) => $q->lockForUpdate())
            ->whereIn('status', [SessionStatus::Ongoing->value, SessionStatus::Ended->value])
            ->exists();
    }
}
