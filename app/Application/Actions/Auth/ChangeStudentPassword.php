<?php

namespace App\Application\Actions\Auth;

use App\Domain\Exceptions\InvalidCredentialsException;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;

final class ChangeStudentPassword
{
    /**
     * @throws InvalidCredentialsException if current_password doesn't match.
     */
    public function __invoke(Student $student, string $currentPassword, string $newPassword): Student
    {
        if (! Hash::check($currentPassword, $student->password)) {
            throw new InvalidCredentialsException('Current password is incorrect.', 422);
        }

        $student->update([
            'password' => $newPassword, // Student casts 'password' => 'hashed'
            'must_change_password' => false,
        ]);

        return $student;
    }
}
