<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Department::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Normalize before validating so the uniqueness check and the
        // stored value always agree on casing (BSIT === bsit === BSit).
        $this->merge([
            'code' => strtoupper((string) $this->input('code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:departments,code'],
        ];
    }
}
