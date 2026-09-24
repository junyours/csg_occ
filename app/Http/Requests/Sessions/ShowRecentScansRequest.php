<?php

namespace App\Http\Requests\Sessions;

use App\Application\Actions\Sessions\BuildRecentScans;
use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;

class ShowRecentScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewRecentScans', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Validated here *and* clamped in BuildRecentScans: this
            // stops a bad value at the edge with a 422 the developer can
            // see, while the action stays safe to call from anywhere
            // else (tests, future jobs) without re-validating.
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.BuildRecentScans::MAX_LIMIT],
        ];
    }
}
