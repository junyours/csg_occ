<?php

namespace App\Http\Requests\Sessions;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;

class ScanAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Officer/ScAdmin/CsgAdmin/SystemAdmin may scan; a plain Student
        // may not. See AttendanceSessionPolicy::scan for the reasoning —
        // this used to be a blanket `true` for any authenticated account.
        return $this->user()?->can('scan', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }
}
