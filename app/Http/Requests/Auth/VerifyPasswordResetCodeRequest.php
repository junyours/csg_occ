<?php

namespace App\Http\Requests\Auth;

use App\Models\PasswordResetCode;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordResetCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50'],
            // A string of exactly 6 digits. Format problems are rejected
            // here, before the action runs, so a malformed request never
            // burns one of the code's 5 attempts.
            'code' => ['required', 'digits:'.PasswordResetCode::CODE_LENGTH],
        ];
    }
}
