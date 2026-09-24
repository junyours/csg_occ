<?php

namespace App\Models;

use App\Domain\Enums\Semester as SemesterEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Semester extends Model
{
    protected $fillable = ['academic_year_id', 'name', 'start_date', 'end_date', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'name' => SemesterEnum::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(EventModel::class);
    }

    /**
     * Students currently enrolled in this semester (spec: "students to a
     * semester") — a student's current-term scope, not a full enrollment
     * history.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'created_by');
    }

    /**
     * @param  Builder<Semester>  $query
     * @return Builder<Semester>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Semester>  $query
     * @return Builder<Semester>
     */
    public function scopeForAcademicYear(Builder $query, int $academicYearId): Builder
    {
        return $query->where('academic_year_id', $academicYearId);
    }
}
