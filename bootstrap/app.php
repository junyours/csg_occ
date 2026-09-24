<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // trustProxies(at: '*') used to sit here, trusting every proxy's
        // X-Forwarded-For header unconditionally. On shared hosting with
        // no load balancer/CDN in front of the app, that lets any client
        // spoof its own IP just by sending that header — which quietly
        // defeats the IP-based login/API rate limiters in
        // AppServiceProvider, since Limit::by($request->ip()) would then
        // be keying off an attacker-supplied value instead of the real
        // connecting IP.
        //
        // TRUSTED_PROXIES is unset (trust nothing, use the real
        // connecting IP) unless explicitly configured. If this app sits
        // behind a real reverse proxy/load balancer/CDN, set
        // TRUSTED_PROXIES in .env to that proxy's IP (or CIDR range),
        // comma-separated for more than one — e.g.
        // TRUSTED_PROXIES=10.0.0.1,10.0.0.2. Only set it to '*' if you
        // have independently verified every request actually passes
        // through a proxy you control that overwrites X-Forwarded-For
        // rather than appending to it.
        $trustedProxies = env('TRUSTED_PROXIES');

        if (filled($trustedProxies)) {
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : array_map('trim', explode(',', $trustedProxies)),
            );
        }

        $middleware->alias([
            'password.changed' => \App\Http\Middleware\EnsurePasswordHasBeenChanged::class,
            'photo.uploaded' => \App\Http\Middleware\EnsurePhotoHasBeenUploaded::class,
        ]);

        // Global security headers for the SPA shell and API responses.
        $middleware->append(\App\Http\Middleware\AddSecurityHeaders::class);

        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            return route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
