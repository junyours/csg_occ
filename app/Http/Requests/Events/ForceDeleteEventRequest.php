<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;

class ForceDeleteEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('forceDelete', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'confirm_name' => ['required', 'string', 'max:255'],
        ];
    }
}
