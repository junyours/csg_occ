<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\ImageManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImageManager::class, fn () => ImageManager::gd());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Nothing in routes/api.php was throttled at all — unlimited
        // password guesses against /auth/login, unlimited hits to every
        // other endpoint. Two named limiters, applied in routes/api.php:
        // 'login' is deliberately tighter and keyed on IP + the attempted
        // username together (not just IP), so it can't be used to lock a
        // real student out of their own account by hammering their
        // username from elsewhere, and can't be dodged by an attacker
        // rotating usernames from one IP.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip().'|'.(string) $request->input('username'));
        });

        // Forgot-password flow. Same idea as 'login' — keyed on IP + the
        // Student ID being targeted — plus a looser per-IP ceiling so one
        // machine can't sweep through many Student IDs. This is only the
        // HTTP layer: the real brute-force guard is the 5-wrong-guesses
        // limit on the code itself (VerifyPasswordResetCode), which holds no
        // matter how many IPs the guesses come from.
        $passwordResetLimiter = function (Request $request) {
            $studentNumber = $request->input('student_number');
            $studentNumber = is_string($studentNumber) ? $studentNumber : '';

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$studentNumber),
                Limit::perMinute(20)->by((string) $request->ip()),
            ];
        };

        RateLimiter::for('password-reset-request', $passwordResetLimiter);
        RateLimiter::for('password-reset-attempt', $passwordResetLimiter);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
