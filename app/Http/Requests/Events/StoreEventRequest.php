<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;

class StoreEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // The event's semester is no longer picked by the person
            // creating it — it's resolved server-side from whichever
            // semester is currently active (see CreateEvent), so there's
            // nothing to validate here.

            // At least one department is required — an event open to no
            // one at all isn't a valid event. The creation form defaults
            // every checkbox to checked, so a CSG Admin submitting without
            // touching anything sends every current department id here.
            'department_ids' => ['required', 'array', 'min:1'],
            'department_ids.*' => ['integer', 'distinct', 'exists:departments,id'],
        ];
    }
}
