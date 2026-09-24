<?php

namespace App\Models;

use App\Domain\Enums\EventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventModel extends Model
{
    protected $table = 'events';

    protected $fillable = ['name', 'description', 'created_by', 'semester_id', 'status'];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class, 'event_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'created_by');
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    /**
     * The departments this event is scoped to (spec: an event can be
     * open to every department, or narrowed to just the ones that need
     * it — e.g. "BSIT only" or "BSIT + BEd, no BSBA"). See
     * includedDepartmentIds() for what an *empty* set here means.
     */
    public function departments(): BelongsToMany
    {
        // Without an explicit foreign pivot key, Eloquent infers one from
        // the model's class name (EventModel -> event_model_id) rather
        // than the actual events table/migration (event_id), so it must
        // be spelled out here to match the event_departments migration.
        return $this->belongsToMany(Department::class, 'event_departments', 'event_id', 'department_id');
    }

    /**
     * The department ids this event is actually open to. An event with
     * zero rows in event_departments is unrestricted — every department
     * currently in the system counts as included — which is both the
     * default state of a brand-new event's creation form (every checkbox
     * starts checked) and the backward-compatible behavior for any event
     * created before department scoping existed.
     *
     * @return array<int, int>
     */
    public function includedDepartmentIds(): array
    {
        $ids = $this->relationLoaded('departments')
            ? $this->departments->pluck('id')->all()
            : $this->departments()->pluck('departments.id')->all();

        return $ids !== [] ? $ids : Department::query()->pluck('id')->all();
    }

    /**
     * Whether a student in $departmentId should ever be tracked for this
     * event at all — the single source of truth every other check
     * (scanning, EndSession's absent sweep, roster/report building)
     * defers to, so "which departments is this event for" only has to be
     * answered in one place.
     */
    public function includesDepartment(?int $departmentId): bool
    {
        return $departmentId !== null && in_array($departmentId, $this->includedDepartmentIds(), true);
    }

    /**
     * An event points at a Semester, which points at an AcademicYear —
     * there's no direct academic_year_id column on events anymore, so
     * this is a read-only accessor rather than a BelongsTo relation.
     * Accessible as both $event->academicYear and $event->academic_year.
     */
    protected function academicYear(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->semester?->academicYear,
        );
    }

    /**
     * @param  Builder<EventModel>  $query
     * @return Builder<EventModel>
     */
    public function scopeForAcademicYear(Builder $query, int $academicYearId): Builder
    {
        return $query->whereHas('semester', function (Builder $semesterQuery) use ($academicYearId) {
            $semesterQuery->where('academic_year_id', $academicYearId);
        });
    }

    /**
     * @param  Builder<EventModel>  $query
     * @return Builder<EventModel>
     */
    public function scopeForSemester(Builder $query, int $semesterId): Builder
    {
        return $query->where('semester_id', $semesterId);
    }
}
