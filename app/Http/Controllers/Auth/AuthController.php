<?php

namespace App\Http\Controllers\Auth;

use App\Application\Actions\Auth\AcceptStudentTerms;
use App\Application\Actions\Auth\AuthenticateStudent;
use App\Application\Actions\Auth\ChangeStudentPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(LoginRequest $request, AuthenticateStudent $authenticateStudent)
    {
        $result = $authenticateStudent(
            $request->string('username')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json([
            'token' => $result['token'],
            // Surfaced at the top level (not just nested in `student`) so the
            // React app can redirect straight to the change-password screen
            // without having to dig into the student object first.
            'must_change_password' => $result['student']->must_change_password,
            'student' => $result['student'],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function changePassword(ChangePasswordRequest $request, ChangeStudentPassword $changeStudentPassword)
    {
        $changeStudentPassword(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('new_password')->toString(),
        );

        return response()->json(['message' => 'Password updated.']);
    }

    public function acceptTerms(Request $request, AcceptStudentTerms $acceptStudentTerms)
    {
        $student = $acceptStudentTerms($request->user());

        return response()->json(['student' => $student]);
    }

    public function me(Request $request)
    {
        // Same reasoning as AuthenticateStudent::__invoke() — the ID/QR
        // screen needs the department name, not just department_id.
        return response()->json($request->user()->load('department'));
    }
}
