<?php

namespace App\Http\Controllers\Auth;

use App\Application\Actions\Auth\RequestPasswordResetCode;
use App\Application\Actions\Auth\ResetPasswordWithCode;
use App\Application\Actions\Auth\VerifyPasswordResetCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyPasswordResetCodeRequest;

/**
 * The signed-out "forgot password" flow, in three calls:
 *  1. requestCode    — student asks for a code (an admin reads it out to them)
 *  2. verifyCode     — student types the code; it is spent, a reset token comes back
 *  3. resetPassword  — student trades the reset token for a new password
 */
class ForgotPasswordController extends Controller
{
    public function requestCode(ForgotPasswordRequest $request, RequestPasswordResetCode $requestPasswordResetCode)
    {
        $result = $requestPasswordResetCode($request->string('student_number')->toString());

        return response()->json([
            // Identical whether or not the Student ID exists.
            'message' => $result['already_active']
                ? 'A code was already requested for this Student ID. You can request a new one once it has been used or has expired.'
                : 'If that Student ID is registered, a 6-digit code is now available from your SC or CSG officer.',
            'retry_after' => $result['retry_after'],
            // true = nothing new was issued because a request is still in effect.
            'already_active' => $result['already_active'],
        ]);
    }

    public function verifyCode(VerifyPasswordResetCodeRequest $request, VerifyPasswordResetCode $verifyPasswordResetCode)
    {
        return response()->json($verifyPasswordResetCode(
            $request->string('student_number')->toString(),
            $request->string('code')->toString(),
        ));
    }

    public function resetPassword(ResetPasswordRequest $request, ResetPasswordWithCode $resetPasswordWithCode)
    {
        $resetPasswordWithCode(
            $request->string('student_number')->toString(),
            $request->string('reset_token')->toString(),
            $request->string('new_password')->toString(),
        );

        return response()->json(['message' => 'Password updated.']);
    }
}
