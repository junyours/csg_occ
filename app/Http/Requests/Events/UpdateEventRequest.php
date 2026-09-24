<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', EventModel::class) ?? false;
    }

    /**
     * event-day-window-edit-delete-plan.md §4.1: name/description only —
     * an event's semester and department_ids aren't editable here (see
     * StoreEventRequest for why department_ids is required at creation
     * but has no equivalent update path). Both fields are 'sometimes'
     * so a PATCH can touch just one of them; 'name' still can't be sent
     * as an empty string on the request that does touch it.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
