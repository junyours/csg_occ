<?php

namespace App\Application\Actions\Auth;

use App\Domain\Enums\Role;
use App\Domain\Exceptions\AccountInactiveException;
use App\Domain\Exceptions\InvalidCredentialsException;
use App\Models\Student;
use Illuminate\Support\Facades\Hash;

final class AuthenticateStudent
{
    /**
     * @return array{student: Student, token: string}
     *
     * @throws InvalidCredentialsException
     */
    public function __invoke(string $username, string $password): array
    {
        $student = Student::where('username', $username)->first();

        if (! $student || ! Hash::check($password, $student->password)) {
            // Same exception and message whether the username doesn't exist
            // or the password is wrong — never reveal which one it was.
            throw new InvalidCredentialsException;
        }

        // Only after the credentials check, so this can't reveal usernames.
        if ($student->isInactive() && $student->role !== Role::SystemAdmin) {
            throw new AccountInactiveException;
        }

        $token = $student->createToken('api')->plainTextToken;

        // Eager-loaded so the login response carries the student's
        // department name/code alongside department_id — the ID/QR
        // screen displays it (spec-adjacent "digital student ID" view)
        // and has no other endpoint available to a plain student that
        // would resolve it after the fact.
        $student->load('department');

        return ['student' => $student, 'token' => $token];
    }
}
