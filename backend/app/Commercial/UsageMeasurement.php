<?php

declare(strict_types=1);

namespace App\Commercial;

use App\Configuration\GlobalBusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Measures consumption against the Usage Limits published on Plan/Add-on Versions (§14.5).
 *
 * Recording is idempotent per client key and hard limits are enforced inside the same
 * transaction that advances the window, so parallel writes cannot exceed a bound. Exceeding
 * a limit never deletes or mutates existing business data.
 */
final class UsageMeasurement
{
    /**
     * Record consumption against a limit for a covered business. Returns the resulting window
     * state. Throws when a hard limit would be exceeded.
     *
     * @param array{idempotency_key?: ?string, occurred_at?: ?string} $options
     * @return array{usage_limit: string, business_id: string, consumed: int, limit: int, warning: bool, state: string, period_start: string, period_end: string}
     */
    public function record(string $limitCode, string $business, int $quantity, array $options = []): array
    {
        abort_unless($quantity >= 0, 422, 'Usage quantity must not be negative.');
        $key = (string) ($options['idempotency_key'] ?? Str::uuid7()->toString());
        $limit = $this->limit($limitCode);

        return DB::transaction(function () use ($limitCode, $business, $quantity, $key, $limit): array {
            $window = $this->currentWindow($limit, $business);

            // Idempotent replay of an already-recorded key reports the existing window unchanged.
            $recorded = DB::table('commercial_usage_events')
                ->where(['usage_limit_code' => $limitCode, 'business_id' => $business, 'idempotency_key' => $key])
                ->first();
            if ($recorded !== null) {
                return $this->state($limit, $window);
            }

            $consumed = (int) $window->consumed + $quantity;
            $ceiling = (int) $limit->limit;
            $within = $consumed <= $ceiling;
            if (! $within && $limit->enforcement === 'hard_block') {
                throw ValidationException::withMessages(['usage' => 'The usage limit for this period has been reached.']);
            }

            $changed = DB::table('commercial_usage_windows')->where('id', $window->id)
                ->where('consumed', $window->consumed)
                ->update(['consumed' => $consumed, 'updated_at' => now()]);
            abort_unless($changed === 1, 409, 'The usage window changed concurrently; retry.');

            DB::table('commercial_usage_events')->insert([
                'id' => Str::uuid7()->toString(), 'usage_limit_code' => $limitCode, 'business_id' => $business,
                'idempotency_key' => $key, 'quantity' => $quantity, 'recorded_at' => now(),
            ]);

            $updated = (object) [...(array) $window, 'consumed' => $consumed];

            return $this->state($limit, $updated);
        }, attempts: 3);
    }

    /**
     * Authorized usage status (limit, threshold, period, consumption) for a business.
     *
     * @return list<array{usage_limit: string, resource: string, unit: string, period: string, consumed: int, limit: int, warning_threshold: int, warning: bool, exceeded: bool, state: string, period_start: string, period_end: string}>
     */
    public function status(string $business): array
    {
        $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE)->toDateString();
        $limits = DB::table('commercial_usage_limits')->orderBy('code')->get();
        $report = [];
        foreach ($limits as $limit) {
            $window = DB::table('commercial_usage_windows')
                ->where(['usage_limit_code' => $limit->code, 'business_id' => $business])
                ->where('period_start', '<=', $today)->where('period_end', '>', $today)
                ->orderByDesc('period_start')->first();
            $consumed = (int) ($window->consumed ?? 0);
            $report[] = [
                'usage_limit' => $limit->code, 'resource' => $limit->resource, 'unit' => $limit->unit,
                'period' => $limit->period, 'consumed' => $consumed, 'limit' => (int) $limit->limit,
                'warning_threshold' => (int) $limit->warning_threshold,
                'warning' => $consumed >= (int) $limit->warning_threshold && $consumed <= (int) $limit->limit,
                'exceeded' => $consumed > (int) $limit->limit,
                'state' => $this->verdict($limit, $consumed),
                'period_start' => $window->period_start ?? $today,
                'period_end' => $window->period_end ?? $today,
            ];
        }

        return $report;
    }

    private function limit(string $code): object
    {
        $limit = DB::table('commercial_usage_limits')->where('code', $code)->first();
        abort_if($limit === null, 404, 'The usage limit is not registered.');

        return $limit;
    }

    private function currentWindow(object $limit, string $business): object
    {
        $today = CarbonImmutable::today(GlobalBusinessSettings::BUSINESS_TIME_ZONE);
        $existing = DB::table('commercial_usage_windows')
            ->where(['usage_limit_code' => $limit->code, 'business_id' => $business])
            ->where('period_start', '<=', $today->toDateString())->where('period_end', '>', $today->toDateString())
            ->lockForUpdate()->orderByDesc('period_start')->first();
        if ($existing !== null) {
            return $existing;
        }

        $start = $today;
        $end = match ($limit->period) {
            'monthly' => $start->addMonth(),
            'quarterly' => $start->addMonths(3),
            'annual' => $start->addYear(),
            default => $start->addDay(),
        };
        DB::table('commercial_usage_windows')->insert([
            'id' => Str::uuid7()->toString(), 'usage_limit_code' => $limit->code, 'business_id' => $business,
            'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            'consumed' => 0, 'updated_at' => now(),
        ]);

        return DB::table('commercial_usage_windows')
            ->where(['usage_limit_code' => $limit->code, 'business_id' => $business])
            ->where('period_start', $start->toDateString())->lockForUpdate()->first();
    }

    private function state(object $limit, object $window): array
    {
        $consumed = (int) $window->consumed;

        return [
            'usage_limit' => $limit->code, 'business_id' => $window->business_id, 'consumed' => $consumed,
            'limit' => (int) $limit->limit, 'warning' => $consumed >= (int) $limit->warning_threshold,
            'state' => $this->verdict($limit, $consumed),
            'period_start' => $window->period_start, 'period_end' => $window->period_end,
        ];
    }

    private function verdict(object $limit, int $consumed): string
    {
        return match (true) {
            $consumed > (int) $limit->limit => match ($limit->enforcement) {
                'hard_block' => 'blocked',
                'authorized_overage' => 'overage',
                default => 'warning',
            },
            $consumed >= (int) $limit->warning_threshold => 'warning',
            default => 'within',
        };
    }
}
