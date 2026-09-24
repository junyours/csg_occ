<?php

namespace App\Http\Requests\Sessions;

use App\Domain\Enums\CheckType;
use App\Domain\Enums\WindowType;
use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', AttendanceSession::class) ?? false;
    }

    /**
     * Every field is 'sometimes' — a PATCH may touch just one of them
     * (e.g. only grace_minutes). Mirrors StoreSessionRequest's rules
     * for the fields it shares, but every value not present in the
     * request falls back to the session's own current value for the
     * uniqueness/relational rules below (Bug #13): a request that
     * doesn't touch window_type/check_type at all must not fail against
     * its own existing row.
     */
    public function rules(): array
    {
        // $this->route('session') is already the resolved
        // AttendanceSession instance by the time rules() runs.
        $session = $this->route('session');

        return [
            'window_type' => ['sometimes', 'required', Rule::enum(WindowType::class)],
            'check_type' => ['sometimes', 'required', Rule::enum(CheckType::class)],
            'start_time' => ['sometimes', 'required', 'date_format:H:i'],
            'end_time' => [
                'sometimes', 'required', 'date_format:H:i',
                // 'after:start_time' only fires when start_time is
                // present in *this* request — if only end_time is being
                // changed, it's compared against the submitted
                // start_time when present, otherwise left to the model
                // layer's existing invariant (the row's own
                // already-valid start_time/end_time pair).
                $this->has('start_time') ? 'after:start_time' : 'after:'.$session?->start_time,
            ],
            'grace_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'penalty_late_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'penalty_absent_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * The (event_day_id, window_type, check_type) uniqueness check used
     * to live as a Rule::unique() attached to the 'window_type' field
     * itself, gated by 'sometimes' — so it only ran when the request
     * body contained a 'window_type' key. That let a request that only
     * changes check_type (leaving window_type untouched) collide with a
     * sibling check silently: validation passed, and the save then blew
     * up with a raw UNIQUE constraint SQL exception instead of a 422.
     *
     * The pair that must be unique can be pushed into collision by
     * either field changing, so the check has to run whenever *either*
     * one is present in the request — not just when 'window_type' is —
     * using each field's submitted value, or the session's own current
     * value for whichever side wasn't touched.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('window_type') && ! $this->has('check_type')) {
                return;
            }

            $session = $this->route('session');

            $windowType = $this->input('window_type', $session?->window_type?->value);
            $checkType = $this->input('check_type', $session?->check_type?->value);

            $collides = AttendanceSession::query()
                ->where('event_day_id', $session?->event_day_id)
                ->where('window_type', $windowType)
                ->where('check_type', $checkType)
                ->when($session, fn ($q) => $q->where('id', '!=', $session->id))
                ->exists();

            if ($collides) {
                $validator->errors()->add('window_type', 'This window and check type combination already exists for this day.');
            }
        });
    }
}
