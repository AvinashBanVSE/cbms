<?php

declare(strict_types=1);

namespace App\Configuration;

use Illuminate\Support\Facades\DB;

final class BusinessTimePresentation
{
    public const CLOCKS = ['12-hour', '24-hour'];

    public function resolve(string $portal, string $business): array
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
        $settings = DB::table('business_time_presentation')->whereIn('business_id', array_keys($chain))->get()->keyBy('business_id');

        $effective = ['clock' => null, 'source' => 'locale_default', 'locked_by' => null];
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
            if ($entry->clock !== null) {
                $effective['clock'] = $entry->clock;
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
                'clock' => $local?->clock, 'override_allowed' => (bool) ($local?->override_allowed ?? false),
                'locked' => (bool) ($local?->locked ?? false), 'version' => (int) ($local?->version ?? 0),
            ],
        ];
    }
}
