<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The whole point: the person asking has forgotten their password,
        // so there is nobody authenticated to gate against.
        return true;
    }

    public function rules(): array
    {
        return [
            'student_number' => ['required', 'string', 'max:50'],
        ];
    }
}
