<?php

namespace App\Http\Requests\Events;

use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RescheduleEventDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Reschedule is a variant of the same "edit/delete a day" power
        // as deleteDay — see EventModelPolicy::deleteDay's docblock.
        return $this->user()?->can('deleteDay', EventModel::class) ?? false;
    }

    /**
     * Note: this only validates shape and per-row existence. The
     * *actual* "does the final date set — including every untouched
     * day's existing date — have any duplicates" check, and the "is
     * every targeted day still eligible" check, are both re-verified
     * with fresh lockForUpdate() reads inside RescheduleEventDays itself
     * (§4.5 point 5 / Bug #11) rather than here, since a FormRequest's
     * validation runs before any row lock is taken and can't be trusted
     * at commit time.
     */
    public function rules(): array
    {
        // $this->route('event') is already the resolved EventModel
        // instance by the time rules() runs.
        $event = $this->route('event');

        return [
            'targets' => ['required', 'array', 'min:1'],
            'targets.*.event_day_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('event_days', 'id')->where('event_id', $event?->id),
            ],
            'targets.*.date' => ['required', 'date'],
        ];
    }
}
