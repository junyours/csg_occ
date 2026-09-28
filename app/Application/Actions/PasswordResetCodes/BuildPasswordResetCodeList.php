<?php

namespace App\Application\Actions\PasswordResetCodes;

use App\Domain\Enums\PasswordResetCodeStatus;
use App\Models\PasswordResetCode;
use App\Models\Student;

final class BuildPasswordResetCodeList
{
    /**
     * The Password Reset Codes screen: the codes this admin is allowed to
     * read out to students (see PasswordResetCode::scopeVisibleTo for who
     * sees what).
     *
     * Read-only — loading the list never deletes anything. Used and expired
     * rows stay visible, marked as such, until an admin presses "Clear used
     * & expired" (see DeleteDeadPasswordResetCodes). Nothing on the server
     * sweeps them in the background either: this screen is opened rarely,
     * so there is no polling and no scheduled job behind it.
     *
     * @return array{now: string, data: list<array<string, mixed>>}
     */
    public function __invoke(Student $actor): array
    {
        $now = now();

        $rows = PasswordResetCode::query()
            ->visibleTo($actor)
            ->with('student.department')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return [
            // Lets the browser correct for a clock that's off — the
            // countdown is only trustworthy if both ends agree on "now".
            'now' => $now->toIso8601String(),
            'data' => $rows->map(function (PasswordResetCode $row) use ($now) {
                $status = $row->status($now);

                return [
                    'id' => $row->id,
                    'student_id' => $row->student_id,
                    'student_number' => $row->student->student_number,
                    'first_name' => $row->student->first_name,
                    'last_name' => $row->student->last_name,
                    'role' => $row->student->role->value,
                    'department_code' => $row->student->department?->code,
                    'photo_url' => $row->student->photo_url,
                    'status' => $status->value,
                    // Only a usable code is ever put on the wire — a spent,
                    // locked or expired one has nothing left to disclose.
                    'code' => $status === PasswordResetCodeStatus::Pending ? $row->code : null,
                    'attempts' => $row->attempts,
                    'created_at' => $row->created_at->toIso8601String(),
                    'expires_at' => $row->expires_at->toIso8601String(),
                ];
            })->all(),
        ];
    }
}
