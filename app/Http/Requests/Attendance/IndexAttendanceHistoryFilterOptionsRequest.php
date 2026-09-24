<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;

class IndexAttendanceHistoryFilterOptionsRequest extends FormRequest
{
    /**
     * Same boundary as IndexAttendanceHistoryRequest — this endpoint only
     * feeds the autosuggest on the ledger's Major/Year level/Section
     * filters, so whoever can see the ledger (including an Officer
     * limited to their own scans) can see the distinct values that
     * populate it.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewOwnScanHistory', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
