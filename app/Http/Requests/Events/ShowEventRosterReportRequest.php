<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowEventRosterReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewRosterReport', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Not required — a CSG/System Admin can leave these blank to
            // get every department/year/section as separate groups in one
            // export; an SC Admin's department is forced server-side
            // regardless of what's passed here (see the controller).
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'major' => ['nullable', 'string', 'max:50'],
            'year_level' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:50'],
            'format' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ];
    }
}
