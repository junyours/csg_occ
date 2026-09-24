<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class AcademicYear extends Model
{
    protected $fillable = ['name', 'start_date', 'end_date', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Events no longer point at an academic year directly — they point at
     * a Semester, which points at the academic year. Kept here as a
     * convenience read path (e.g. "every event in AY 2026-2027") via the
     * semesters relation rather than a direct FK.
     */
    public function events(): HasManyThrough
    {
        return $this->hasManyThrough(EventModel::class, Semester::class, 'academic_year_id', 'semester_id');
    }

    public function semesters(): HasMany
    {
        return $this->hasMany(Semester::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'created_by');
    }

    /**
     * @param  Builder<AcademicYear>  $query
     * @return Builder<AcademicYear>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
