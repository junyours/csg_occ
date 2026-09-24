<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEventDayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateDay', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        // $this->route('eventDay') is already the resolved EventDay
        // instance by the time rules() runs (same timing as
        // StoreEventDayRequest's $this->route('event')).
        //
        // Bug #13: without ->ignore($eventDay->id), saving a day's date
        // *unchanged* — or any edit that doesn't touch date at all —
        // would incorrectly fail this uniqueness check against itself.
        $eventDay = $this->route('eventDay');

        return [
            'date' => [
                'required', 'date',
                Rule::unique('event_days', 'date')
                    ->where('event_id', $eventDay?->event_id)
                    ->ignore($eventDay?->id),
            ],
        ];
    }
}
