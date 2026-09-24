<?php

namespace App\Models;

use App\Domain\Enums\Role;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Student extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'student_number', 'last_name', 'first_name', 'middle_name', 'suffix',
        'department_id', 'major', 'year_level', 'section', 'date_enrolled', 'role', 'sc_admin_department_id',
        'officer_event_id', 'semester_id', 'photo_path', 'qr_token', 'qr_version', 'username', 'password',
        'must_change_password', 'has_accepted_terms',
    ];

    protected $hidden = ['password', 'remember_token', 'qr_token'];

    // Spec §4.5: the React app needs a ready-to-use image URL, not a raw
    // storage path — appended so it rides along on every JSON response
    // without controllers having to remember to add it.
    protected $appends = ['photo_url'];

    protected function photoUrl(): Attribute
    {
        // Deliberately root-relative, not Storage::disk('public')->url().
        // That helper builds the URL from the static APP_URL config, which
        // breaks the moment the app is reached under a different host than
        // APP_URL says (127.0.0.1 vs localhost, a LAN IP, a tunnel, a
        // staging domain) — the <img> then points at a host that isn't the
        // one that served the page. Since this is a same-origin SPA (see
        // AddSecurityHeaders' CSP comment), a path relative to whatever
        // origin loaded the page is always correct and needs no env config
        // to match the browsing host.
        return Attribute::make(
            get: fn () => $this->photo_path ? '/storage/'.$this->photo_path : null,
        );
    }

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'must_change_password' => 'boolean',
            'has_accepted_terms' => 'boolean',
            'date_enrolled' => 'date',
            'password' => 'hashed',
        ];
    }

    /**
     * Whether this student can have attendance tracked for a given event.
     *
     * Two independent gates, both must pass:
     *  1. Only a plain Student is ever eligible. System Admin, CSG Admin,
     *     SC Admin, and Officer are all staff — they run or staff events
     *     rather than attend them (spec §2) — and are never eligible,
     *     regardless of which event is asked about or how (or whether) an
     *     Officer is scoped via officer_event_id.
     *  2. The student's own department must be one this event is scoped
     *     to (see EventModel::includesDepartment) — an event narrowed to
     *     e.g. BSIT + BEd should never track (or later mark absent/
     *     penalize) a BSBA student just because they happen to scan.
     *
     * Callers that need a *different* error message for each gate (e.g.
     * ScanAttendance, which tells an officer "wrong department" instead
     * of "not an attendee at all") check the two conditions separately
     * rather than calling this — this method is for callers that only
     * need the combined yes/no (e.g. UpdateStudentPhoto's photo lock).
     */
    public function isAttendanceEligibleForEvent(int $eventId): bool
    {
        if ($this->role !== Role::Student) {
            return false;
        }

        $event = EventModel::with('departments')->find($eventId);

        return $event !== null && $event->includesDepartment($this->department_id);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scAdminDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'sc_admin_department_id');
    }

    public function officerEvent(): BelongsTo
    {
        return $this->belongsTo(EventModel::class, 'officer_event_id');
    }

    /**
     * The semester this student is currently enrolled in (spec: "students
     * to a semester"). Defaults to the active semester at creation time
     * (see CreateStudent) but is a plain nullable FK, not enforced here.
     */
    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
