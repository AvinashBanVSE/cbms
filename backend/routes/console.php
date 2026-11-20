<?php

declare(strict_types=1);

use App\Commercial\SubscriptionRenewal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('commercial:renew-subscriptions', function (): void {
    app(SubscriptionRenewal::class)->runAutomatic();
})->purpose('Prepare and activate explicitly authorized automatic Subscription renewals');

Schedule::command('commercial:renew-subscriptions')->everyFiveMinutes()->withoutOverlapping();
