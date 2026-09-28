<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50'],
            // Str::random(64) in VerifyPasswordResetCode.
            'reset_token' => ['required', 'string', 'size:64'],
            // Same complexity rule as ChangePasswordRequest (and the same
            // client-side checklist): 8+ chars, upper + lower, number,
            // symbol. There's no `different:` rule here — the whole reason
            // they're resetting is that they don't remember the old one.
            // Validated before the token is looked at, so a too-weak
            // password can be corrected and resubmitted without the token
            // having been spent.
            'new_password' => [
                'required',
                'string',
                Password::min(8)->mixedCase()->numbers()->symbols(),
                'confirmed',
            ],
        ];
    }
}
