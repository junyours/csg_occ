<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEventDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        // $this->route('event') is already the resolved EventModel instance
        // by the time rules() runs — SubstituteBindings runs before the
        // FormRequest is validated — so this scopes the day_number
        // uniqueness check to this specific event, matching the
        // (event_id, day_number) unique constraint in the migration.
        $event = $this->route('event');

        return [
            'date' => ['required', 'date'],
            'day_number' => [
                'required', 'integer', 'min:1',
                Rule::unique('event_days', 'day_number')->where('event_id', $event?->id),
            ],
        ];
    }
}
