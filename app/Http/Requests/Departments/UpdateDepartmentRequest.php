<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', Department::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Same normalization as StoreDepartmentRequest so the uniqueness
        // check and the stored value always agree on casing.
        if ($this->has('code')) {
            $this->merge([
                'code' => strtoupper((string) $this->input('code')),
            ]);
        }
    }

    public function rules(): array
    {
        // Route model binding resolves before rules() runs, so
        // $this->route('department') is a real Department here.
        $department = $this->route('department');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('departments', 'code')->ignore($department),
            ],
        ];
    }
}
