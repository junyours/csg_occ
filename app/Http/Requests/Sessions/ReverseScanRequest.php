<?php

namespace App\Http\Requests\Sessions;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;

class ReverseScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same tier as scanning itself — see
        // AttendanceSessionPolicy::reverseScan for why this isn't
        // narrowed to admins.
        return $this->user()?->can('reverseScan', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Optional, unlike ReversePenaltyRequest's required reason.
            // That one is an admin sitting at a desk excusing a charge
            // after the fact; this one is an officer with a queue of
            // students in front of them, and forcing them to type before
            // the line can move would just teach them to type "x". The
            // audit row still always carries a reason — the action fills
            // in ReverseAttendanceRecord::DEFAULT_REASON when none is
            // given — and the scan screen offers one-tap presets so the
            // common cases land something meaningful without typing.
            'reason' => ['nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
