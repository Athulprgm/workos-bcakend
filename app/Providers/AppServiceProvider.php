<?php

namespace App\Providers;

use App\Models\Bug;
use App\Models\Task;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Stable morph names for GitHub issue/PR associations. This is a
        // partial map on purpose: Sanctum tokens and tags keep their default
        // class morph names.
        Relation::morphMap([
            'task' => Task::class,
            'bug' => Bug::class,
        ]);

        // Baseline throughput guard for every API route, keyed by IP. This runs
        // before authentication (see bootstrap/app.php) so that unauthenticated
        // traffic against protected routes is still counted. Per-user keys would
        // require a second limiter placed after authentication instead.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Tighter limit for credential submission, keyed by email + IP so one
        // attacker cannot lock out an unrelated account.
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        if ($this->app->runningInConsole()) {
            // Keep the two-way bot and the existing digest/sync scheduler
            // running alongside the normal local dev processes.
            if (filled(config('services.telegram.bot_token'))) {
                DevCommands::artisan('telegram:poll', 'telegram');
            }

            DevCommands::artisan('schedule:work', 'schedule');
        }
    }
}
