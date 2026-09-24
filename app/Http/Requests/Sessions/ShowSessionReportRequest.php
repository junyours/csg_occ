<?php

namespace App\Http\Requests\Sessions;

use App\Models\AttendanceSession;
use Illuminate\Foundation\Http\FormRequest;

class ShowSessionReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewReport', AttendanceSession::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
