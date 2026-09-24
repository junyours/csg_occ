<?php

namespace App\Http\Requests\Reports;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;

class ShowMasterReportRequest extends FormRequest
{
    /**
     * Same gate as the per-event roster report (EventModelPolicy::
     * viewRosterReport) — System/CSG/SC Admin only, an Officer runs the
     * scanner, not the paperwork.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewRosterReport', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
