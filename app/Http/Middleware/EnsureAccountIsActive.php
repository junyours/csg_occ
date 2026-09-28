<?php

namespace App\Http\Middleware;

use App\Domain\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request enforcement of `inactive`. 401 (not 403) so the React client
 * interceptor logs the person out. System Admin is exempt.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isInactive() && $user->role !== Role::SystemAdmin) {
            return response()->json([
                'message' => 'This account is inactive. Please contact the CSG or System Admin.',
            ], 401);
        }

        return $next($request);
    }
}
