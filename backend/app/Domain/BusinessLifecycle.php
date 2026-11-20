<?php

declare(strict_types=1);

namespace App\Domain;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessLifecycle
{
    public const KINDS = ['Organization', 'Distributor', 'Shop'];

    public const TRANSITIONS = [
        'Draft' => ['Pending Verification', 'Active'],
        'Pending Verification' => ['Active'],
        'Active' => ['Suspended', 'Deactivated', 'Closed'],
        'Suspended' => ['Active', 'Deactivated', 'Closed'],
        'Deactivated' => ['Active', 'Closed'],
        'Closed' => [],
    ];

    public function transition(string $id, string $actor, array $input): array
    {
        return DB::transaction(function () use ($id, $actor, $input): array {
            $rows = DB::table('business_identities')->where(fn (Builder $query) => $query->where('id', $id)->orWhere('parent_id', $id))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $root = $rows->get($id);
            abort_if($root === null || ! in_array($root->kind, self::KINDS, true), 404);
            $operation = $input['operation_id'];
            $previous = DB::table('business_lifecycle_operations')->where('id', $operation)->first();
            if ($previous) {
                abort_unless($previous->business_id === $id && $previous->actor_id === $actor
                    && $previous->target_state === $input['state'] && $previous->reason === $input['reason'], 409);

                return json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR);
            }
            $target = $input['state'];
            $from = $root->operational_state;
            abort_unless((int) $root->lifecycle_version === (int) $input['expected_version']
                && in_array($target, self::TRANSITIONS[$from], true), 409);
            if ($target === 'Active' && $from === 'Pending Verification') {
                abort_unless((bool) ($input['verification_confirmed'] ?? false), 422, 'Confirm that business verification has been completed.');
            }
            if ($target === 'Active' && $root->parent_id !== null) {
                abort_unless(DB::table('business_identities')->where('id', $root->parent_id)->value('operational_state') === 'Active', 409, 'The Parent Organization must be active.');
            }

            $mappings = $this->mappingQuery($rows->keys()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($target === 'Closed') {
                $this->requireClosureReadiness($rows->except($id), $mappings);
            }

            $restore = $target === 'Active' && in_array($from, ['Suspended', 'Deactivated'], true)
                ? DB::table('business_lifecycle_operations')->where('id', $root->lifecycle_operation_id)->where('business_id', $id)->first() : null;
            $recorded = now();
            $original = $restore ? json_decode($restore->snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
            $entities = [$id => $target];
            foreach ($rows->except($id) as $childId => $child) {
                $next = $original
                    ? $this->restoration($original['entities'][$childId] ?? null, $child, $restore->id, 'operational_state')
                    : $this->cascade($child->operational_state, $target, 'Closed');
                if ($next !== null) {
                    $entities[$childId] = $next;
                }
            }

            $owners = DB::table('ownership_links')->whereIn('business_id', $rows->keys())
                ->orderBy('owner_id')->get(['business_id', 'owner_id'])->groupBy('business_id');
            $snapshot = ['entities' => [], 'mappings' => []];
            foreach ($rows as $key => $business) {
                $snapshot['entities'][$key] = $this->entry($business, 'operational_state', $entities[$key] ?? null) + [
                    'parent_id' => $business->parent_id, 'owner_ids' => $owners->get($key, collect())->pluck('owner_id')->all(),
                ];
            }

            $mapChanges = [];
            foreach ($mappings as $key => $mapping) {
                if ($original) {
                    $next = $this->restoration($original['mappings'][$key] ?? null, $mapping, $restore->id, 'state');
                    // An unconsumed setup code is never recovered by lifecycle restoration.
                    if (! in_array($next, ['Active', 'Suspended'], true) || ! $this->endpointsActive($mapping, $entities)) {
                        $next = null;
                    }
                } else {
                    $affected = isset($entities[$mapping->shop_id]) || isset($entities[$mapping->distributor_id]);
                    $next = $affected ? $this->cascade($mapping->state, $target, 'Terminated') : null;
                }
                $snapshot['mappings'][$key] = $this->entry($mapping, 'state', $next) + [
                    'shop_id' => $mapping->shop_id, 'distributor_id' => $mapping->distributor_id,
                ];
                if ($next !== null) {
                    $mapChanges[$key] = $next;
                }
            }

            $snapshotId = Str::uuid7()->toString();
            $result = [
                'operation_id' => $operation, 'snapshot_id' => $snapshotId, 'state' => $target,
                'version' => (int) $root->lifecycle_version + 1, 'restores_operation_id' => $restore?->id,
                'changed_businesses' => count($entities), 'changed_mappings' => count($mapChanges),
            ];
            DB::table('business_lifecycle_operations')->insert([
                'id' => $operation, 'business_id' => $id, 'snapshot_id' => $snapshotId, 'actor_id' => $actor,
                'target_state' => $target, 'reason' => $input['reason'], 'recorded_at' => $recorded,
                'restores_operation_id' => $restore?->id, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);
            foreach ($entities as $key => $next) {
                DB::table('business_identities')->where('id', $key)->update([
                    'operational_state' => $next, 'lifecycle_operation_id' => $operation,
                    'lifecycle_version' => (int) $rows[$key]->lifecycle_version + 1,
                ]);
                DB::table('business_lifecycle_history')->insert([
                    'business_id' => $key, 'operation_id' => $operation, 'from_state' => $rows[$key]->operational_state, 'to_state' => $next,
                ]);
            }
            foreach ($mapChanges as $key => $next) {
                DB::table('distributor_shop_mappings')->where('id', $key)->update([
                    'state' => $next, 'lifecycle_operation_id' => $operation, 'lifecycle_version' => (int) $mappings[$key]->lifecycle_version + 1,
                    'code_digest' => null, 'expires_at' => null,
                ]);
                DB::table('distributor_shop_mapping_history')->insert([
                    'id' => Str::uuid7()->toString(), 'mapping_id' => $key, 'actor_id' => $actor, 'action' => 'lifecycle.'.$next,
                    'recorded_at' => $recorded, 'operation_id' => $operation,
                ]);
            }
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $id,
                'action' => 'business.lifecycle_changed', 'recorded_at' => $recorded,
                'details' => json_encode($result + ['reason' => $input['reason'], 'authorization' => 'business_structure.lifecycle', 'verification_confirmed' => (bool) ($input['verification_confirmed'] ?? false)], JSON_THROW_ON_ERROR),
            ]);

            return $result;
        }, attempts: 3);
    }

    private function mappingQuery(array $entities): Builder
    {
        return DB::table('distributor_shop_mappings')->where(fn (Builder $query) => $query
            ->whereIn('shop_id', $entities)->orWhereIn('distributor_id', $entities));
    }

    private function requireClosureReadiness(iterable $children, iterable $mappings): void
    {
        foreach ($children as $child) {
            abort_unless($child->operational_state === 'Closed', 409, 'Close each direct child before closing the Organization.');
        }
        foreach ($mappings as $mapping) {
            abort_unless($mapping->state === 'Terminated', 409, 'End the outstanding mappings before closure.');
        }
    }

    private function entry(object $row, string $field, ?string $next): array
    {
        return ['before' => $row->{$field}, 'after' => $next, 'version' => (int) $row->lifecycle_version];
    }

    private function cascade(string $current, string $target, string $terminal): ?string
    {
        return match (true) {
            $target === 'Suspended' && $current === 'Active' => 'Suspended',
            $target === 'Deactivated' && ! in_array($current, ['Deactivated', $terminal], true) => 'Deactivated',
            default => null,
        };
    }

    private function restoration(?array $snapshot, object $row, string $cause, string $field): ?string
    {
        return $snapshot !== null && $snapshot['after'] !== null && $row->{$field} === $snapshot['after']
            && $row->lifecycle_operation_id === $cause && (int) $row->lifecycle_version === $snapshot['version'] + 1
            ? $snapshot['before'] : null;
    }

    private function endpointsActive(object $mapping, array $changes): bool
    {
        $endpoints = DB::table('business_identities')->whereIn('id', [$mapping->shop_id, $mapping->distributor_id])->get();
        foreach ($endpoints as $endpoint) {
            $parent = $changes[$endpoint->parent_id] ?? DB::table('business_identities')->where('id', $endpoint->parent_id)->value('operational_state');
            if (($changes[$endpoint->id] ?? $endpoint->operational_state) !== 'Active' || $parent !== 'Active') {
                return false;
            }
        }

        return $endpoints->count() === 2;
    }
}
