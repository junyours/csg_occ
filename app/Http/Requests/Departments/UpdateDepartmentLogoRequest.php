<?php

namespace App\Http\Requests\Departments;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateLogo', Department::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Same 5MB/image-mimetype ceiling as a student photo upload
            // (spec §4.5) — no reason a department logo needs a looser rule.
            'logo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }
}
