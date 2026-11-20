<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Commercial\SubscriptionRenewal;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessSubscriptionRenewal implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $subscription,
        public readonly string $account,
        public readonly string $tenant,
        public readonly string $provider,
        public readonly ?string $predecessor,
    ) {}

    public function uniqueId(): string
    {
        return $this->subscription;
    }

    public function handle(SubscriptionRenewal $renewals): void
    {
        $renewals->processAutomatic($this);
    }
}
