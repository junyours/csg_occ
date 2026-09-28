<?php

namespace App\Application\Actions\Auth;

use App\Domain\Exceptions\InvalidPasswordResetSessionException;
use App\Models\PasswordResetCode;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

final class ResetPasswordWithCode
{
    /**
     * Final step of "forgot password": trades the reset token from
     * VerifyPasswordResetCode for a new password.
     *
     * The token is bound to the student it was issued for, works once, and
     * lapses 5 minutes after the code was verified. On success every API
     * token the student already holds is revoked — someone who forgot their
     * password may well have a session open somewhere else, and a password
     * reset should end it.
     *
     * @throws InvalidPasswordResetSessionException
     */
    public function __invoke(string $studentNumber, string $resetToken, string $newPassword): void
    {
        $done = DB::transaction(function () use ($studentNumber, $resetToken, $newPassword) {
            $student = Student::where('student_number', trim($studentNumber))->first();

            if ($student === null) {
                return false;
            }

            $row = PasswordResetCode::query()
                ->where('student_id', $student->id)
                ->whereNotNull('used_at')
                ->where('reset_token_hash', hash('sha256', $resetToken))
                ->where('reset_token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $student->update([
                'password' => $newPassword, // Student casts 'password' => 'hashed'
                // They chose this password themselves, so the first-login
                // "you must change your default password" gate is satisfied.
                'must_change_password' => false,
            ]);

            $row->update(['reset_token_hash' => null, 'reset_token_expires_at' => null]);

            $student->tokens()->delete();

            return true;
        });

        if (! $done) {
            throw new InvalidPasswordResetSessionException;
        }
    }
}
