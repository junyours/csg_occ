<?php

namespace App\Http\Requests\Penalties;

use Illuminate\Foundation\Http\FormRequest;

class ReversePenaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route model binding resolves (or 404s) before authorize() runs
        // — same reasoning as UpdateStudentRoleRequest — so $this->route
        // ('penalty') is always a real AttendancePenalty here.
        return $this->user()?->can('reverse', $this->route('penalty')) ?? false;
    }

    public function rules(): array
    {
        return [
            // Required: a CSG Admin excusing a penalty after the fact
            // (spec §7.3) needs a documented reason for the audit trail,
            // same as the reason already stored on penalty creation.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}
