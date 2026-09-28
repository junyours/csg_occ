<?php

namespace App\Application\Actions\Auth;

use App\Models\PasswordResetCode;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class RequestPasswordResetCode
{
    /** A new code can be generated once every 5 minutes — the same as a code's lifetime. */
    public const COOLDOWN_SECONDS = PasswordResetCode::TTL_MINUTES * 60;

    /**
     * Generates a 6-digit code for the student, for an SC/CSG/System admin to
     * read out to them. Nothing is sent to the student.
     *
     * Only one code can be alive per student. While a code has neither been
     * used nor expired, asking again does NOT issue another one — it just
     * reports how long until a new request is allowed (`already_active`).
     * Two independent guards enforce that:
     *  - the rate limiter, keyed on the Student ID *string* so an unknown ID
     *    behaves identically to a real one (same answer, same countdown,
     *    nothing to tell them apart), and
     *  - the code row itself, so the rule still holds if the cache was
     *    cleared (deploy, cache flush) while a code is still waiting.
     *
     * @return array{retry_after: int, already_active: bool}
     */
    public function __invoke(string $studentNumber): array
    {
        $studentNumber = trim($studentNumber);
        $key = self::cooldownKey($studentNumber);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return ['retry_after' => max(1, RateLimiter::availableIn($key)), 'already_active' => true];
        }

        $student = Student::where('student_number', $studentNumber)->first();

        if ($student !== null) {
            $live = PasswordResetCode::where('student_id', $student->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->first();

            if ($live !== null) {
                $remaining = max(1, $live->expires_at->getTimestamp() - now()->getTimestamp());

                // Put the cooldown back in step with the code that is
                // actually waiting, in case the cache lost it.
                RateLimiter::hit($key, $remaining);

                return ['retry_after' => $remaining, 'already_active' => true];
            }
        }

        RateLimiter::hit($key, self::COOLDOWN_SECONDS);

        if ($student !== null) {
            $this->issueCode($student);
        }

        return ['retry_after' => self::COOLDOWN_SECONDS, 'already_active' => false];
    }

    public static function cooldownKey(string $studentNumber): string
    {
        return 'password-reset-request:'.sha1(mb_strtolower(trim($studentNumber)));
    }

    private function issueCode(Student $student): void
    {
        DB::transaction(function () use ($student) {
            // A fresh code supersedes everything older for this student:
            // any code still waiting to be used stops working, and any
            // half-finished reset session (code verified, password not yet
            // set) is revoked. Normally there's nothing to do here — the
            // cooldown equals the code lifetime — but it keeps "one live
            // code per student" true even if the cooldown cache was lost.
            PasswordResetCode::where('student_id', $student->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            PasswordResetCode::where('student_id', $student->id)
                ->whereNotNull('reset_token_hash')
                ->update(['reset_token_hash' => null, 'reset_token_expires_at' => null]);

            PasswordResetCode::create([
                'student_id' => $student->id,
                // random_int is a CSPRNG; rand()/mt_rand() are not.
                'code' => str_pad((string) random_int(0, 999999), PasswordResetCode::CODE_LENGTH, '0', STR_PAD_LEFT),
                'expires_at' => now()->addMinutes(PasswordResetCode::TTL_MINUTES),
            ]);
        });
    }
}
