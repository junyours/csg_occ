<?php

namespace App\Http\Requests\Reports;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartMasterReportGenerationRequest extends FormRequest
{
    /**
     * Same gate as the per-event roster report and the master summary
     * screen — System/CSG/SC Admin only.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewRosterReport', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'event_ids' => ['required', 'array', 'min:1'],
            'event_ids.*' => ['integer', 'distinct', 'exists:events,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'major' => ['nullable', 'string', 'max:50'],
            'year_level' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ];
    }
}
