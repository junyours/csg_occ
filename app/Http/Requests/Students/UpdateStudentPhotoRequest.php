<?php

namespace App\Http\Requests\Students;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('student');

        return $target instanceof Student
            && ($this->user()?->can('updatePhoto', $target) ?? false);
    }

    public function rules(): array
    {
        return [
            // Spec §4.5: 5MB ceiling, restricted to image mimetypes.
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }
}
