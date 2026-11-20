<?php

declare(strict_types=1);

namespace App\Domain;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceProviderMigration
{
    public function preview(string $source, string $destination, ?string $tenant): array
    {
        $main = DB::table('portal_installation')->where('id', 1)->value('portal_id');
        $providers = DB::table('business_identities')->whereIn('id', [$source, $destination, $main])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($providers->get($source)?->kind === 'Sub-Portal', 422, 'Choose a Sub-Portal as the source.');
        abort_unless($source !== $destination && in_array($providers->get($destination)?->kind, ['Main Portal', 'Sub-Portal'], true)
            && $providers[$destination]->operational_state === 'Active', 422, 'Choose a different active service provider.');
        $tenants = DB::table('customer_tenants')->where('provider_id', $source)
            ->when($tenant !== null, fn ($query) => $query->where('id', $tenant))
            ->orderBy('id')->lockForUpdate()->get(['id', 'organization_id']);
        $defaults = DB::table('provider_credential_defaults')->whereIn('provider_id', [$source, $destination, $main])->pluck('mode', 'provider_id');
        $sourceMode = $defaults[$source] ?? $defaults[$main] ?? null;
        $destinationMode = $defaults[$destination] ?? $defaults[$main] ?? null;
        $policies = DB::table('organization_credential_policies')->whereIn('tenant_id', $tenants->pluck('id'))->pluck('mode', 'tenant_id');
        $businesses = DB::table('business_identities')->whereIn('id', $tenants->pluck('organization_id'))
            ->orWhereIn('parent_id', $tenants->pluck('organization_id'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'kind', 'parent_id', 'professional_name']);
        $roots = $businesses->keyBy('id');
        $children = $businesses->whereNotNull('parent_id')->groupBy('parent_id');
        $owners = DB::table('ownership_links')->whereIn('business_id', $businesses->pluck('id'))
            ->orderBy('owner_id')->get(['business_id', 'owner_id', 'id'])->groupBy('business_id');

        $manifest = [];
        $blocked = [];
        foreach ($tenants as $context) {
            $organization = $roots->get($context->organization_id);
            $members = $children->get($context->organization_id, collect());
            $ownerIds = $owners->get($context->organization_id, collect())->pluck('owner_id')->all();
            if ($organization?->kind !== 'Organization' || $organization->parent_id !== null || $ownerIds === []
                || $members->contains(fn ($child): bool => ! in_array($child->kind, ['Distributor', 'Shop'], true)
                    || $owners->get($child->id, collect())->pluck('owner_id')->all() !== $ownerIds)) {
                $blocked[$context->id][] = 'Business structure or ownership requires correction.';
            }
            $before = $policies[$context->id] ?? $sourceMode;
            $after = $policies[$context->id] ?? $destinationMode;
            if ($before !== $after) {
                $blocked[$context->id][] = 'Credential setup policy would change. Set the Organization policy explicitly or align the provider defaults.';
            }
            $manifest[] = [
                'tenant_id' => $context->id, 'organization_id' => $context->organization_id,
                'name' => $organization?->professional_name,
                'credential_mode' => $before, 'destination_credential_mode' => $after,
                'businesses' => $members->prepend($organization)->filter()->map(fn ($member): array => [
                    'id' => $member->id, 'kind' => $member->kind, 'parent_id' => $member->parent_id,
                    'ownership_links' => $owners->get($member->id, collect())->pluck('id', 'owner_id')->all(),
                ])->all(),
            ];
        }

        $fingerprint = hash('sha256', json_encode([$source, $destination, $tenant, $manifest, $blocked], JSON_THROW_ON_ERROR));

        return ['fingerprint' => $fingerprint, 'tenants' => $manifest, 'blocked' => $blocked, 'can_execute' => $manifest !== [] && $blocked === []];
    }

    public function execute(string $actor, array $input): array
    {
        return DB::transaction(function () use ($actor, $input): array {
            $operation = $input['operation_id'];
            $source = $input['source_id'];
            $destination = $input['destination_id'];
            // Serialize retries before the persisted operation is checked.
            DB::table('business_identities')->whereIn('id', [$source, $destination, DB::table('portal_installation')->where('id', 1)->value('portal_id')])
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $existing = DB::table('service_provider_migrations')->where('id', $operation)->first();
            if ($existing !== null) {
                abort_unless($existing->actor_id === $actor && $existing->source_id === $source && $existing->destination_id === $destination
                    && $existing->fingerprint === $input['fingerprint'] && $existing->reason === $input['reason'], 409);

                return ['operation_id' => $operation, 'tenants_migrated' => count(json_decode($existing->manifest, true, flags: JSON_THROW_ON_ERROR))];
            }

            $plan = $this->preview($source, $destination, $input['tenant_id'] ?? null);
            abort_unless($plan['can_execute'] && hash_equals($plan['fingerprint'], $input['fingerprint']), 409, 'The migration scope has changed or requires correction. Preview it again.');
            $ids = array_column($plan['tenants'], 'tenant_id');
            $updated = DB::table('customer_tenants')->whereIn('id', $ids)->where('provider_id', $source)->update(['provider_id' => $destination]);
            abort_unless($updated === count($ids), 409);
            DB::table('service_provider_migrations')->insert([
                'id' => $operation, 'source_id' => $source, 'destination_id' => $destination, 'actor_id' => $actor,
                'reason' => $input['reason'], 'fingerprint' => $plan['fingerprint'],
                'manifest' => json_encode($plan['tenants'], JSON_THROW_ON_ERROR), 'recorded_at' => now(),
            ]);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $source,
                'action' => 'service_provider.migrated', 'recorded_at' => now(),
                'details' => json_encode(['operation_id' => $operation, 'destination_id' => $destination, 'tenants_migrated' => $updated], JSON_THROW_ON_ERROR),
            ]);

            return ['operation_id' => $operation, 'tenants_migrated' => $updated];
        }, attempts: 3);
    }
}
