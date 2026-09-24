<?php

it('sets baseline security headers on an api response', function () {
    // /api/auth/login is reachable unauthenticated, so it's a convenient
    // real endpoint to inspect headers on without any fixture setup.
    $response = $this->postJson('/api/auth/login', []);

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    // CSP is deliberately skipped on /api/* responses (JSON, never
    // rendered — see AddSecurityHeaders' own docblock).
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('sets a content security policy on the spa shell', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Content-Security-Policy');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'");
});

it('keeps font and style origins self-hosted now that Inter is bundled by Vite instead of linked from Google Fonts', function () {
    $csp = $this->get('/')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("font-src 'self' data:")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->not->toContain('fonts.googleapis.com')
        ->not->toContain('fonts.gstatic.com');
});

it('skips the content security policy entirely in local development, since the Vite dev server needs origins CSP cannot safely express', function () {
    app()->detectEnvironment(fn () => 'local');

    $response = $this->get('/');

    // Other security headers still apply — only CSP is environment-gated.
    $response->assertHeader('X-Frame-Options', 'DENY');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('still sets a content security policy on the spa shell in non-local environments', function () {
    app()->detectEnvironment(fn () => 'testing');

    $response = $this->get('/');

    $response->assertHeader('Content-Security-Policy');
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-ancestors 'none'");
});
