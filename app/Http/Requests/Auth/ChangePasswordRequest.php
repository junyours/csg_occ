<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated account may change its own password —
        // there's nothing role-specific about this action.
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // 'confirmed' expects a matching new_password_confirmation field.
            // 'different' stops someone from "changing" it to the same value
            // just to clear the must_change_password flag. The Password rule
            // mirrors the client-side checklist shown on the change-password
            // screen (uppercase, lowercase, number, symbol) — the frontend
            // check is only for live feedback, this is the real gate.
            'new_password' => [
                'required',
                'string',
                Password::min(8)->mixedCase()->numbers()->symbols(),
                'confirmed',
                'different:current_password',
            ],
        ];
    }
}
