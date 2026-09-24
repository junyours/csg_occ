<?php

namespace App\Http\Requests\Sessions;

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\EventModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EventModel::class) ?? false;
    }

    public function rules(): array
    {
        // Mirrors the (event_day_id, window_type, check_type) unique
        // constraint in the migration — one time-in and one time-out
        // session per window per day, max. The unique rule is attached to
        // window_type (rather than check_type) so a duplicate combination
        // reads naturally as "this window already has that check" on the
        // form field the person is actually choosing between.
        $eventDay = $this->route('eventDay');

        return [
            'window_type' => [
                'required', Rule::enum(WindowType::class),
                Rule::unique('attendance_sessions', 'window_type')
                    ->where('event_day_id', $eventDay?->id)
                    ->where('check_type', $this->input('check_type')),
            ],
            'check_type' => ['required', Rule::enum(CheckType::class)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'grace_minutes' => ['nullable', 'integer', 'min:0'],
            'penalty_late_amount' => ['nullable', 'numeric', 'min:0'],
            'penalty_absent_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
