<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendanceHistoryRequest extends FormRequest
{
    /**
     * viewOwnScanHistory covers both tiers this endpoint now serves:
     * System Admin/CSG Admin/SC Admin get the full ledger (viewReport),
     * and an Officer gets in too but only ever sees their own scans —
     * AttendanceHistoryController forces that scoping once we're past
     * this gate, the same way it forces department_id for an SC Admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewOwnScanHistory', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // An SC Admin may further narrow within their own department,
            // but may not widen past it — enforced in the controller
            // (query scoping), same as StudentController/PenaltyController.
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'major' => ['nullable', 'string', 'max:50'],
            'year_level' => ['nullable', 'string', 'max:20'],
            'section' => ['nullable', 'string', 'max:50'],
            'event_id' => ['nullable', 'integer', 'exists:events,id'],
            'status' => ['nullable', 'string', Rule::in(['all', 'present', 'late', 'absent', 'excluded'])],
            // Matches against student_number/last_name/first_name of
            // either the attendee or the scanning officer.
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'string', Rule::in(['recent', 'name', 'course', 'year_level', 'section', 'major', 'event'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
