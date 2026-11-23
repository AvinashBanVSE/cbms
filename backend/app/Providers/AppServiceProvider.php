<?php

declare(strict_types=1);

namespace App\Providers;

use App\Validation\RequiredPasswordVerifier;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend(UncompromisedVerifier::class, fn () => new RequiredPasswordVerifier($this->app->make(Factory::class)));
    }

    public function boot(): void
    {
        RateLimiter::for('organization-verify', static fn (Request $request): Limit => Limit::perMinute(15)->by($request->ip()));

        // Mapping request lifecycle limiters (§4.2). These are referenced by name from the
        // mapping routes; each is keyed per user and IP so a burst of secret exchanges is
        // throttled without leaking one business's pace to another.
        RateLimiter::for('mapping-initiate', static fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mapping-verify', static fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mapping-confirm', static fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));

        Event::listen([Login::class, Failed::class, Logout::class], static function (Login|Failed|Logout $event): void {
            $action = match (true) {
                $event instanceof Login => 'login.succeeded',
                $event instanceof Failed => 'login.failed',
                $event instanceof Logout => 'logout',
            };

            DB::table('portal_events')->insert([
                'id' => (string) Str::uuid7(),
                'action' => $action,
                'actor_id' => $event->user?->getAuthIdentifier(),
                'recorded_at' => now(),
            ]);
        });
    }
}
