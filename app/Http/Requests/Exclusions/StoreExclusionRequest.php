<?php

namespace App\Http\Requests\Exclusions;

use App\Domain\Enums\ExclusionScope;
use App\Domain\Enums\WindowType;
use App\Models\Exclusion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Exclusion::class) ?? false;
    }

    /**
     * student-exclusion-feature-plan.md §3/§5: scope is event|day|window.
     * event_day_id is required for both day and window scope (window is
     * one specific day+window_type — see App\Domain\Enums\ExclusionScope);
     * window_type is required only for window scope. reason is always
     * required (§2 rule 5 / §5 step 3), and is validated here — not left
     * to CreateExclusion — so a missing reason surfaces as a normal 422
     * rather than a DB-level NOT NULL failure.
     */
    public function rules(): array
    {
        return [
            'student_number' => ['required_without:qr_token', 'nullable', 'string'],
            'qr_token' => ['required_without:student_number', 'nullable', 'string'],

            'event_id' => ['required', 'integer', 'exists:events,id'],
            'scope' => ['required', Rule::enum(ExclusionScope::class)],
            'event_day_id' => [
                Rule::requiredIf(fn () => in_array($this->input('scope'), ['day', 'window'], true)),
                'nullable', 'integer', 'exists:event_days,id',
            ],
            'window_type' => ['required_if:scope,window', 'nullable', Rule::enum(WindowType::class)],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
