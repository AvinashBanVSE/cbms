<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\CustomerBusinessAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessMappingController
{
    public function __construct(private readonly CustomerBusinessAccess $access) {}

    public function initiate(Request $request, string $shop): JsonResponse
    {
        $actor = $this->access->requirePermission($shop, 'Shop', 'mapping.initiate');
        $input = $request->validate(['distributor_id' => 'required|uuid']);
        abort_unless(DB::table('business_identities')->where('id', $input['distributor_id'])->where('kind', 'Distributor')->exists(), 422);

        return DB::transaction(function () use ($shop, $actor, $input): JsonResponse {
            $this->requireActiveEndpoints($shop, $input['distributor_id']);
            $id = Str::uuid7()->toString();
            $secret = bin2hex(random_bytes(16));
            DB::table('distributor_shop_mappings')->insert([
                'id' => $id, 'shop_id' => $shop, 'distributor_id' => $input['distributor_id'],
                'state' => 'pending_distributor', ...$this->codeFields($secret),
            ]);
            $this->record($id, $actor, 'initiated');

            return $this->secretResponse($id, $secret, 201);
        });
    }

    public function verifyShop(Request $request, string $distributor, string $mapping): JsonResponse
    {
        $actor = $this->access->requirePermission($distributor, 'Distributor', 'mapping.verify');
        $input = $request->validate(['shop_id' => 'required|uuid', 'code' => 'required|string|size:32']);

        return DB::transaction(function () use ($mapping, $distributor, $actor, $input): JsonResponse {
            $secret = bin2hex(random_bytes(16));
            $this->consume($mapping, $input['shop_id'], $distributor, 'pending_distributor', $input['code'], [
                'state' => 'pending_shop', ...$this->codeFields($secret),
            ]);
            $this->record($mapping, $actor, 'shop_code_verified');

            return $this->secretResponse($mapping, $secret);
        });
    }

    public function confirm(Request $request, string $shop, string $mapping): JsonResponse
    {
        $actor = $this->access->requirePermission($shop, 'Shop', 'mapping.confirm');
        $input = $request->validate(['distributor_id' => 'required|uuid', 'code' => 'required|string|size:32']);

        return DB::transaction(function () use ($mapping, $shop, $actor, $input): JsonResponse {
            $this->consume($mapping, $shop, $input['distributor_id'], 'pending_shop', $input['code'], [
                'state' => 'Active', 'code_digest' => null, 'expires_at' => null,
            ]);
            $this->record($mapping, $actor, 'activated');

            return response()->json(['id' => $mapping, 'state' => 'Active']);
        });
    }

    public function end(string $business, string $mapping): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = DB::table('business_identities')->where('id', $business)->value('kind');
        abort_unless(in_array($kind, ['Shop', 'Distributor'], true), 403);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.end');

        return DB::transaction(function () use ($mapping, $business, $kind, $actor): JsonResponse {
            $endpoint = $kind === 'Shop' ? 'shop_id' : 'distributor_id';
            $issuer = $kind === 'Shop' ? 'pending_distributor' : 'pending_shop';
            $changed = DB::table('distributor_shop_mappings')->where('id', $mapping)->where($endpoint, $business)
                ->whereIn('state', [$issuer, 'Active', 'Suspended', 'Deactivated'])->update([
                    'state' => 'Terminated', 'code_digest' => null, 'expires_at' => null,
                    'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1'),
                ]);
            abort_unless($changed === 1, 409);
            $this->record($mapping, $actor, 'terminated');

            return response()->json(['id' => $mapping, 'state' => 'Terminated']);
        });
    }

    private function consume(string $id, string $shop, string $distributor, string $state, string $code, array $next): void
    {
        $this->requireActiveEndpoints($shop, $distributor);
        $query = DB::table('distributor_shop_mappings')->where([
            'id' => $id, 'shop_id' => $shop, 'distributor_id' => $distributor, 'state' => $state,
        ])->where('expires_at', '>', now());
        $digest = $query->value('code_digest');
        abort_unless(is_string($digest) && hash_equals($digest, hash('sha256', $code)), 422, 'Mapping code is unavailable or invalid.');
        abort_unless($query->where('code_digest', $digest)->update([...$next, 'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1')]) === 1, 422, 'Mapping code is unavailable or invalid.');
    }

    private function requireActiveEndpoints(string $shop, string $distributor): void
    {
        $endpoints = DB::table('business_identities')->whereIn('id', [$shop, $distributor])->orderBy('id')->lockForUpdate()->get();
        abort_unless($endpoints->count() === 2, 422);
        foreach ($endpoints as $endpoint) {
            abort_unless($endpoint->operational_state === 'Active'
                && DB::table('business_identities')->where('id', $endpoint->parent_id)->value('operational_state') === 'Active', 409, 'Both businesses must be active.');
        }
    }

    private function codeFields(string $code): array
    {
        return ['code_digest' => hash('sha256', $code), 'expires_at' => now()->addMinutes(config('portal.mapping_code_minutes'))];
    }

    private function secretResponse(string $id, string $secret, int $status = 200): JsonResponse
    {
        return response()->json(['id' => $id, 'code' => $secret], $status)->header('Cache-Control', 'no-store');
    }

    private function record(string $id, string $actor, string $action): void
    {
        $record = ['actor_id' => $actor, 'recorded_at' => now()];
        DB::table('distributor_shop_mapping_history')->insert([
            ...$record, 'id' => Str::uuid7()->toString(), 'mapping_id' => $id, 'action' => $action,
        ]);
        DB::table('portal_events')->insert([
            ...$record, 'id' => Str::uuid7()->toString(), 'subject_id' => $id, 'action' => 'mapping.'.$action,
        ]);
    }
}
