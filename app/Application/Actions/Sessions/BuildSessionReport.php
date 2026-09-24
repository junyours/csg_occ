<?php

namespace App\Application\Actions\Sessions;

use App\Domain\Enums\Role;
use App\Models\AttendancePenalty;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Exclusion;
use App\Models\Student;

final class BuildSessionReport
{
    /**
     * Spec §10 (GET /sessions/{id}/report) and §8's note that exclusions
     * "should also visibly say 'Excluded' rather than 'Absent' in
     * reports". Every role=student account in scope gets a row — even one
     * with no attendance_records row at all, which happens whenever the
     * session hasn't been ended yet. That row is reported with a null
     * status (still pending), never guessed at as Absent — only
     * EndSession is allowed to actually decide Absent.
     *
     * @param  int|null  $departmentId  When given (an SC Admin's own
     *                                  department), the roster and every
     *                                  count below are scoped to it. Null
     *                                  reports on every department.
     * @return array{session_id: int, window_type: string, check_type: string, session_status: string, summary: array, students: array}
     */
    public function __invoke(AttendanceSession $session, ?int $departmentId = null): array
    {
        $excludedStudentIds = Exclusion::excludedStudentIdsForSession($session);

        // Never lists a student whose department isn't part of this
        // event's scope (see EventModel::includedDepartmentIds) — they
        // were never eligible to attend, so they shouldn't show up as a
        // perpetually-pending row either.
        $includedDepartmentIds = $session->eventDay->event->includedDepartmentIds();

        $roster = Student::where('role', Role::Student)
            ->whereIn('department_id', $includedDepartmentIds)
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->with('department')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $records = AttendanceRecord::where('session_id', $session->id)
            ->whereIn('student_id', $roster->pluck('id'))
            ->get()
            ->keyBy('student_id');

        $penaltyTotals = AttendancePenalty::where('session_id', $session->id)
            ->where('is_reversed', false)
            ->whereIn('student_id', $roster->pluck('id'))
            ->get()
            ->groupBy('student_id')
            ->map(fn ($penalties) => (float) $penalties->sum('amount'));

        $summary = ['present' => 0, 'late' => 0, 'absent' => 0, 'excluded' => 0, 'pending' => 0, 'penalty_total' => 0.0];
        $students = [];

        foreach ($roster as $student) {
            $isExcluded = in_array($student->id, $excludedStudentIds, true);
            $record = $records->get($student->id);

            // A real record always wins over the exclusion flag: it can
            // only exist here because the student genuinely scanned
            // before being excluded (student-exclusion-feature-plan.md
            // §2 rule 4's "mid-window guard") — that Present/Late/Absent
            // outcome is not rewritten to "Excluded" after the fact.
            [$status, $scannedAt] = match (true) {
                $record !== null => [$record->status->value, $record->scanned_at],
                $isExcluded => ['excluded', null],
                default => [null, null], // not yet scanned, session still open
            };

            $penaltyAmount = $penaltyTotals->get($student->id, 0.0);

            $summary['penalty_total'] += $penaltyAmount;
            $summary[$status ?? 'pending']++;

            $students[] = [
                'student_id' => $student->id,
                'student_number' => $student->student_number,
                'last_name' => $student->last_name,
                'first_name' => $student->first_name,
                'department_code' => $student->department?->code,
                'year_level' => $student->year_level,
                'section' => $student->section,
                'status' => $status,
                'scanned_at' => $scannedAt,
                'penalty_amount' => $penaltyAmount,
            ];
        }

        return [
            'session_id' => $session->id,
            'window_type' => $session->window_type->value,
            'check_type' => $session->check_type->value,
            'session_status' => $session->status->value,
            'summary' => $summary,
            'students' => $students,
        ];
    }
}
