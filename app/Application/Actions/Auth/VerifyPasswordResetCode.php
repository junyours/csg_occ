<?php

namespace App\Application\Actions\Auth;

use App\Domain\Exceptions\InvalidPasswordResetCodeException;
use App\Domain\Exceptions\PasswordResetCodeLockedException;
use App\Models\PasswordResetCode;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VerifyPasswordResetCode
{
    /**
     * Spends the code and returns a short-lived, single-use reset token that
     * the "set new password" step must present.
     *
     * The code is consumed here, at verification — not when the password is
     * finally changed — so going back to the "enter code" screen after a
     * successful check is impossible: the same code will never work twice.
     *
     * @return array{reset_token: string, expires_in: int}
     *
     * @throws InvalidPasswordResetCodeException wrong/expired/used/unknown
     * @throws PasswordResetCodeLockedException  too many wrong attempts
     */
    public function __invoke(string $studentNumber, string $code): array
    {
        // Decided inside the transaction, thrown after it. Throwing from
        // inside DB::transaction() would roll back the attempts++ below —
        // and a wrong guess that doesn't stick is no throttle at all.
        $result = DB::transaction(function () use ($studentNumber, $code) {
            $student = Student::where('student_number', trim($studentNumber))->first();

            $row = $student === null ? null : PasswordResetCode::query()
                ->where('student_id', $student->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['outcome' => 'invalid'];
            }

            if ($row->attempts >= PasswordResetCode::MAX_ATTEMPTS) {
                return ['outcome' => 'locked', 'retry_after' => $this->secondsUntilExpiry($row)];
            }

            if (! hash_equals((string) $row->code, $code)) {
                // Not $row->save(): see updateKeepingExpiry() — a plain
                // save() let the database rewrite expires_at.
                $row->updateKeepingExpiry(['attempts' => $row->attempts + 1]);

                $remaining = PasswordResetCode::MAX_ATTEMPTS - $row->attempts;

                return $remaining > 0
                    ? ['outcome' => 'wrong', 'attempts_remaining' => $remaining]
                    : ['outcome' => 'locked', 'retry_after' => $this->secondsUntilExpiry($row)];
            }

            $token = Str::random(64);

            $row->update([
                'used_at' => now(),
                'reset_token_hash' => hash('sha256', $token),
                'reset_token_expires_at' => now()->addMinutes(PasswordResetCode::TTL_MINUTES),
            ]);

            return ['outcome' => 'ok', 'reset_token' => $token];
        });

        return match ($result['outcome']) {
            'ok' => [
                'reset_token' => $result['reset_token'],
                'expires_in' => PasswordResetCode::TTL_MINUTES * 60,
            ],
            'locked' => throw new PasswordResetCodeLockedException($result['retry_after']),
            'wrong' => throw new InvalidPasswordResetCodeException($result['attempts_remaining']),
            default => throw new InvalidPasswordResetCodeException,
        };
    }

    private function secondsUntilExpiry(PasswordResetCode $row): int
    {
        return max(1, $row->expires_at->getTimestamp() - now()->getTimestamp());
    }
}
