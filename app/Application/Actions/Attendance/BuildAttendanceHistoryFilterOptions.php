<?php

namespace App\Application\Actions\Attendance;

use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;

final class BuildAttendanceHistoryFilterOptions
{
    /**
     * Distinct major/year-level/section values for the attendance
     * history ledger's free-text filters to autosuggest against (see
     * AttendanceHistoryAdminPage — those fields are plain inputs, not
     * selects, since nothing backs them with a fixed enum). Sourced from
     * the students table rather than attendance_records, so a value
     * suggests as soon as a student exists with it, before their first
     * scan.
     *
     * Scoped the same way BuildAttendanceHistory scopes department_id
     * for an SC Admin, so their suggestions never leak another
     * department's majors/sections.
     *
     * @param array{department_id?: int} $filters
     * @return array{majors: list<string>, year_levels: list<string>, sections: list<string>}
     */
    public function __invoke(array $filters): array
    {
        $base = Student::query();

        if (! empty($filters['department_id'])) {
            $base->where('department_id', $filters['department_id']);
        }

        return [
            'majors' => $this->distinctValues(clone $base, 'major'),
            'year_levels' => $this->distinctValues(clone $base, 'year_level'),
            'sections' => $this->distinctValues(clone $base, 'section'),
        ];
    }

    /**
     * @return list<string>
     */
    private function distinctValues(Builder $query, string $column): array
    {
        return $query->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
