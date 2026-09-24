<?php

use Illuminate\Support\Facades\Route;

// Catch-all: every non-API route serves the same SPA shell so react-router
// can handle client-side routes (e.g. a hard refresh on /students/42 still
// works instead of 404ing on the server).
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api).*$');
