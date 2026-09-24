<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the must_change_password gate (spec §4.3:
 * "never trust the client alone"). Applied to every protected route except
 * auth/logout and auth/change-password itself, so a student stuck on their
 * default password can't skip the reset screen just by calling the API
 * directly instead of going through the React app's redirect.
 */
class EnsurePasswordHasBeenChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
