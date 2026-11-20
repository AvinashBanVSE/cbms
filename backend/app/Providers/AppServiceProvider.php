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
        RateLimiter::for('organization-verify', static function (Request $request): array {
            $code = $request->input('code');
            $key = is_string($code) && preg_match('/^[A-Z]{2}[0-9]{4}$/D', $code) ? $code : 'invalid';

            return [
                Limit::perMinute(15)->by('ip:'.$request->ip()),
                Limit::perHour(3)->by('code:'.$key)->after(static fn ($response): bool => $response->getStatusCode() === 422),
            ];
        });

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
