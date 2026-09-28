<?php

namespace App\Http\Requests\PasswordResetCodes;

use App\Models\PasswordResetCode;
use Illuminate\Foundation\Http\FormRequest;

class IndexPasswordResetCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PasswordResetCode::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
