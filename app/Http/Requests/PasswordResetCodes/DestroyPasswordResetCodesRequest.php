<?php

namespace App\Http\Requests\PasswordResetCodes;

use App\Models\PasswordResetCode;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPasswordResetCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Anyone who can open the screen can clear the dead rows on it.
        return $this->user()?->can('viewAny', PasswordResetCode::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
