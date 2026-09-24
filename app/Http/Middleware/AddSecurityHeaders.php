<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers — Laravel sets none of these by default.
 * Nothing here changes behavior for a legitimate request; it only removes
 * a few free attack surfaces (clickjacking, MIME-sniffing) for an app
 * that handles student PII (names, photos, attendance/penalty history).
 *
 * Deliberately conservative: this app is a same-origin SPA (see
 * routes/web.php's catch-all serving the same `app` view react-router
 * handles client-side) with no third-party embeds, trackers, or CDN
 * dependencies — Inter is bundled by Vite and served from this
 * origin (see resources/js/app.tsx), not loaded from Google Fonts. If a
 * third-party script/font/embed is ever added, its origin needs to be
 * added to the relevant directive below or it will be silently blocked
 * by the browser, not by this middleware.
 */
class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Skipped for two kinds of response:
        //   - /api/* — JSON, never rendered as a page, CSP is meaningless
        //     there and just adds header weight to every request.
        //   - local development — the Vite dev server serves unbundled
        //     modules and HMR updates from its own origin (not "self"),
        //     and @viteReactRefresh injects an inline <script> preamble.
        //     Worse, Vite reports whatever host Node resolved "localhost"
        //     to when it started (often the IPv6 loopback [::1] on
        //     Windows), and browsers reject a bracketed IPv6 literal as
        //     an invalid CSP source expression — so there's no reliable
        //     way to allow-list it. None of this exists in a production
        //     build: @vite() there emits normal same-origin
        //     <script src="/build/..."> tags, so the strict policy below
        //     only needs to hold outside local dev.
        if (! $request->is('api/*') && ! app()->environment('local')) {
            $response->headers->set('Content-Security-Policy', $this->buildContentSecurityPolicy());
        }

        return $response;
    }

    private function buildContentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            "img-src 'self' data:",
            // Inter is now bundled by Vite and served from
            // this origin — no external font-src origin needed.
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self'",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
        ];

        return implode('; ', $directives);
    }
}
