<?php

namespace App\Application\Actions\Students;

use App\Models\PasswordResetCode;
use App\Models\Student;
use App\Models\StudentPasswordReset;
use Illuminate\Support\Facades\DB;

final class ResetStudentPassword
{
    /**
     * Puts the student's password back to the default and makes them choose a
     * new one at their next sign-in — exactly the first-login flow.
     *
     * Also:
     *  - signs them out everywhere (every API token is revoked), since the
     *    old password may be the very thing that's compromised;
     *  - kills any forgot-password code still waiting to be used, so a code
     *    requested earlier can't be used to skip past this reset;
     *  - writes an audit row (who reset whose password, and when).
     *
     * Who may do this is decided by StudentPolicy::resetPassword, not here.
     */
    public function __invoke(Student $student, Student $actor): Student
    {
        DB::transaction(function () use ($student, $actor) {
            $student->update([
                'password' => CreateStudent::DEFAULT_PASSWORD, // Student casts 'password' => 'hashed'
                'must_change_password' => true,
            ]);

            $student->tokens()->delete();

            PasswordResetCode::where('student_id', $student->id)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            PasswordResetCode::where('student_id', $student->id)
                ->whereNotNull('reset_token_hash')
                ->update(['reset_token_hash' => null, 'reset_token_expires_at' => null]);

            StudentPasswordReset::create([
                'student_id' => $student->id,
                'reset_by' => $actor->id,
            ]);
        });

        return $student->refresh();
    }
}
