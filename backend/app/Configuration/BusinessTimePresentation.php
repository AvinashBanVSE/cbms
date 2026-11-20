<?php

declare(strict_types=1);

namespace App\Configuration;

use Illuminate\Support\Facades\DB;

final class BusinessTimePresentation
{
    public const OPTIONS = [
        'clock' => ['12-hour', '24-hour'],
        'date_format' => ['day-month-year', 'year-month-day'],
        'negative_amount' => ['minus', 'parentheses'],
        'working_days' => ['1', '2', '3', '4', '5', '6', '7'],
        'week_start' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
    ];

    public function persist(string $business, string $setting, array $values): void
    {
        if ($setting === 'clock') {
            DB::table('business_time_presentation')->upsert(
                ['business_id' => $business, ...$values], ['business_id'], array_keys($values),
            );

            return;
        }

        $value = $values[$setting];
        if ($setting === 'working_days' && $value !== null) {
            $value = implode('', $value);
        }
        $entry = ['value' => $value, ...array_diff_key($values, [$setting => true])];
        DB::table('business_presentation_preferences')->upsert(
            ['business_id' => $business, 'setting' => $setting, ...$entry],
            ['business_id', 'setting'], array_keys($entry),
        );
    }

    public function resolve(string $portal, string $business, string $setting = 'clock'): array
    {
        $target = DB::table('business_identities as business')
            ->leftJoin('business_identities as parent', 'parent.id', '=', 'business.parent_id')
            ->where('business.id', $business)->first(['business.kind', 'business.parent_id', 'parent.kind as parent_kind']);
        abort_unless($target !== null && (
            $business === $portal || $target->kind === 'Organization'
            || (in_array($target->kind, ['Shop', 'Distributor'], true) && $target->parent_kind === 'Organization')
        ), 404);

        $chain = [$portal => 'platform'];
        if ($target->parent_kind === 'Organization' && in_array($target->kind, ['Shop', 'Distributor'], true)) {
            $chain[$target->parent_id] = 'organization';
        }
        if ($business !== $portal) {
            $chain[$business] = $target->kind === 'Organization' ? 'organization' : 'business';
        }
        $entries = $setting === 'clock'
            ? DB::table('business_time_presentation')->select('business_id', 'clock as value', 'override_allowed', 'locked', 'version')
            : DB::table('business_presentation_preferences')->where('setting', $setting);
        $settings = $entries->whereIn('business_id', array_keys($chain))->get()->keyBy('business_id');

        $effective = [$setting => null, 'source' => 'locale_default', 'locked_by' => null];
        $local = $settings->get($business);
        $upstreamLock = null;
        foreach ($chain as $id => $scope) {
            if ($id === $business) {
                $upstreamLock = $effective['locked_by'];
            }
            $entry = $settings->get($id);
            if ($entry === null || $effective['locked_by'] !== null || ($scope !== 'platform' && ! $entry->override_allowed)) {
                continue;
            }
            if ($entry->value !== null) {
                $effective[$setting] = $this->value($setting, $entry->value);
                $effective['source'] = $scope;
            }
            if ($entry->locked) {
                $effective['locked_by'] = $scope;
            }
        }

        return [
            'business_id' => $business, 'scope' => $chain[$business], 'effective' => $effective,
            'upstream_lock' => $upstreamLock,
            'local' => [
                $setting => $this->value($setting, $local?->value), 'override_allowed' => (bool) ($local?->override_allowed ?? false),
                'locked' => (bool) ($local?->locked ?? false), 'version' => (int) ($local?->version ?? 0),
            ],
        ];
    }

    private function value(string $setting, ?string $value): string|array|null
    {
        return $setting === 'working_days' && $value !== null ? str_split($value) : $value;
    }
}
