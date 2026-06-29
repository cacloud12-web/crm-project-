<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $maxAttempts = (int) config('crm_queue.login_max_attempts', 5);

            return Limit::perMinute($maxAttempts)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
