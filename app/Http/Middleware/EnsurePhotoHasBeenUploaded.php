<?php

namespace App\Http\Middleware;

use App\Domain\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the "photo on file" gate — the same
 * never-trust-the-client-alone treatment, and now the same *scope*, that
 * EnsurePasswordHasBeenChanged already gives must_change_password.
 * Registered on the whole authenticated route group (see routes/api.php's
 * photo.uploaded group, nested inside password.changed exactly the way
 * that group sits inside auth:sanctum), so a student with no photo on
 * file is blocked from the entire API — not just the QR endpoint — the
 * instant they authenticate. The frontend's ProtectedRoute mirrors this
 * client-side (see needsPhotoUpload) and hard-redirects to
 * /complete-profile, but this middleware is what makes that redirect
 * actually enforceable: a refresh, a direct API call, or a stale client
 * can never quietly skip it the way a transient toast could.
 *
 * Only ever fires for the Student role, never for an admin/officer
 * request — those roles aren't the ones being scanned in at the gate, so
 * nothing here gates them. That's also why an admin fetching a student's
 * QR to print an ID card before that student has ever logged in (see
 * StudentPolicy::updatePhoto) is untouched: this middleware only ever
 * looks at the *requesting* user, never the target of the request.
 *
 * Exempt, like /auth/change-password is from password.changed: the photo
 * upload route itself (see routes/api.php) — otherwise a student could
 * never clear the gate they're stuck behind.
 */
class EnsurePhotoHasBeenUploaded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role === Role::Student && ! $user->photo_path) {
            return response()->json([
                'message' => 'Upload a verification photo before viewing your QR code.',
            ], 423);
        }

        return $next($request);
    }
}
