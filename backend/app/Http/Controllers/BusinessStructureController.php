<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Commercial\SubscriptionCoverage;
use App\Domain\BusinessStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BusinessStructureController
{
    public function __construct(private readonly MainPortalAccess $access) {}

    public function index(): JsonResponse
    {
        $this->access->requirePermission('business_structure.view');

        $directory = DB::table('business_identities as business')
            ->leftJoin('customer_tenants as tenant', 'business.id', '=', 'tenant.organization_id')
            ->select('business.*', 'tenant.id as tenant_id', 'tenant.provider_id')
            ->orderBy('business.id')->simplePaginate(30);

        $owners = DB::table('ownership_links')->join('ownership_holders', 'ownership_holders.id', '=', 'ownership_links.owner_id')
            ->whereIn('business_id', $directory->getCollection()->pluck('id'))
            ->select('business_id', 'owner_id', 'ownership_holders.name')->get()->groupBy('business_id');
        $directory->through(static function (object $business) use ($owners): object {
            $business->owners = $owners->get($business->id, []);

            return $business;
        });

        return response()->json($directory)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $this->access->requirePermission('business_structure.create');
        $data = $request->validate([
            'kind' => 'required|in:Sub-Portal,Organization,Distributor,Shop',
            'professional_name' => 'required|string|max:200',
            'legal_name' => 'required_if:kind,Sub-Portal,Organization|nullable|string|max:200',
            'parent_id' => 'required_if:kind,Distributor,Shop|prohibited_unless:kind,Distributor,Shop|nullable|uuid',
            'provider_id' => 'required_if:kind,Organization|prohibited_unless:kind,Organization|nullable|uuid',
            'owner_ids' => 'prohibited_if:kind,Distributor,Shop|array|list|max:30',
            'owner_ids.*' => 'required|uuid|distinct:ignore_case|exists:ownership_holders,id',
            'new_owner_name' => 'prohibited_if:kind,Distributor,Shop|nullable|string|max:200',
        ]);

        return DB::transaction(function () use ($data): JsonResponse {
            $parent = $data['parent_id'] ?? null;
            $provider = $data['provider_id'] ?? null;
            $ownerIds = $this->resolveOwners($data, $parent);
            if ($provider !== null) {
                $kind = DB::table('business_identities')->where('id', $provider)->lockForUpdate()->value('kind');
                if (! in_array($kind, ['Main Portal', 'Sub-Portal'], true)) {
                    throw ValidationException::withMessages(['provider_id' => 'Select Main Portal or a Sub-Portal as the service provider.']);
                }
            }

            $id = (string) Str::uuid7();
            DB::table('business_identities')->insert([
                'id' => $id,
                'kind' => $data['kind'],
                'professional_name' => $data['professional_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'parent_id' => $parent,
                'operational_state' => $data['kind'] === 'Sub-Portal' ? 'Active' : 'Draft',
            ]);
            foreach ($ownerIds as $ownerId) {
                DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'business_id' => $id, 'owner_id' => $ownerId]);
            }
            if ($provider !== null) {
                DB::table('customer_tenants')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $id, 'provider_id' => $provider]);
            }

            if ($parent !== null) {
                SubscriptionCoverage::includeNewChild($parent, $id);
            }
            DB::table('portal_events')->insert([
                'id' => (string) Str::uuid7(), 'action' => 'business.created',
                'actor_id' => Auth::id(), 'subject_id' => $id, 'recorded_at' => now(),
            ]);

            return response()->json(['id' => $id], 201);
        });
    }

    private function resolveOwners(array $data, ?string $parent): array
    {
        if ($parent !== null) {
            $organization = DB::table('business_identities')->where('id', $parent)->where('kind', 'Organization')->lockForUpdate()->first();
            if ($organization === null || $organization->operational_state === 'Closed') {
                throw ValidationException::withMessages(['parent_id' => 'Select an Organization as the direct parent.']);
            }
            $ownerIds = DB::table('ownership_links')->where('business_id', $parent)->pluck('owner_id')->all();
            BusinessStructure::validateChild($organization->kind, $data['kind'], $ownerIds, $ownerIds);

            return $ownerIds;
        }

        $ownerIds = $data['owner_ids'] ?? [];
        if (isset($data['new_owner_name'])) {
            $ownerId = (string) Str::uuid7();
            DB::table('ownership_holders')->insert(['id' => $ownerId, 'name' => $data['new_owner_name']]);
            $ownerIds[] = $ownerId;
        }
        if ($ownerIds === []) {
            throw ValidationException::withMessages(['owner_ids' => 'Select an existing owner or enter a new owner name.']);
        }

        return $ownerIds;
    }
}
