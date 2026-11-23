<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\CustomerBusinessAccess;
use App\Mapping\MappingGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BusinessMappingController
{
    public function __construct(private readonly CustomerBusinessAccess $access, private readonly MappingGate $gate) {}

    /**
     * Either a Shop or a Distributor may open a request (§4.2). The two entry routes share
     * this path: the acting side is the Initiator, the other is the Recipient, and the
     * first pending stage is named after the Recipient (the side that owes the next decision).
     */
    public function initiate(Request $request, string $business): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = $this->mappingKind($business);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.initiate');
        $counterparty = $this->counterpartyKind($kind);
        // Endpoint-based request-field convention, shared with verify/confirm.
        $counterpartyField = $kind === 'Shop' ? 'distributor_id' : 'shop_id';
        $input = $request->validate([
            $counterpartyField => 'required|uuid',
            'mapping_type' => 'sometimes|required|in:distributor_shop',
        ]);
        $counterpartyId = $input[$counterpartyField];
        abort_unless(DB::table('business_identities')->where('id', $counterpartyId)->where('kind', $counterparty)->exists(), 422);
        $shop = $kind === 'Shop' ? $business : $counterpartyId;
        $distributor = $kind === 'Distributor' ? $business : $counterpartyId;

        return DB::transaction(function () use ($input, $shop, $distributor, $counterparty, $actor, $kind): JsonResponse {
            $this->requireActiveEndpoints($shop, $distributor);
            $this->requireFreshPair($shop, $distributor);
            $id = Str::uuid7()->toString();
            $secret = bin2hex(random_bytes(16));
            DB::table('distributor_shop_mappings')->insert([
                'id' => $id, 'shop_id' => $shop, 'distributor_id' => $distributor,
                'mapping_type' => $input['mapping_type'] ?? 'distributor_shop',
                'initiated_by' => $kind,
                'state' => 'pending_'.strtolower($counterparty),
                ...$this->codeFields($secret),
                'request_expires_at' => now()->addDays((int) config('portal.mapping_request_days')),
            ]);
            $this->record($id, $actor, 'initiated');

            return $this->secretResponse($id, $secret, 201);
        });
    }

    /**
     * The Recipient verifies the Initiator's first code (§12.3.2). This opens the second
     * stage, named after the Initiator, who then owes the confirming code.
     */
    public function verify(Request $request, string $business, ?string $mapping = null): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = $this->mappingKind($business);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.verify');
        $counterparty = $this->counterpartyKind($kind);
        // Convention: a Shop endpoint addresses the other side as distributor_id, a
        // Distributor endpoint as shop_id, regardless of which side initiated.
        $input = $request->validate([
            $kind === 'Shop' ? 'distributor_id' : 'shop_id' => 'required|uuid',
            'code' => 'required|string|size:32',
        ]);
        // The acting Recipient is `business`; the Initiator is the counterparty.
        $counterpartyId = $input[$kind === 'Shop' ? 'distributor_id' : 'shop_id'];
        $shop = $kind === 'Shop' ? $business : $counterpartyId;
        $distributor = $kind === 'Distributor' ? $business : $counterpartyId;

        return DB::transaction(function () use ($input, $shop, $distributor, $kind, $counterparty, $mapping, $actor): JsonResponse {
            $secret = bin2hex(random_bytes(16));
            // The acting side is the Recipient, so the row is in the stage named after it.
            $id = $this->consume($mapping, $shop, $distributor, 'pending_'.strtolower($kind), $input['code'], [
                'state' => 'pending_'.strtolower($counterparty), ...$this->codeFields($secret),
            ]);
            if ($id === null) {
                // The request was past its validity and has been marked Expired by consume;
                // returning normally lets this transaction commit the transition.
                return $this->unavailable();
            }
            $this->record($id, $actor, $kind === 'Shop' ? 'shop_code_verified' : 'distributor_code_verified');

            return $this->secretResponse($id, $secret);
        });
    }

    /**
     * The Initiator confirms the Recipient's second code, activating the mapping (§12.3.5).
     * Only the completion of both verifications may reach Active.
     */
    public function confirm(Request $request, string $business, ?string $mapping = null): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = $this->mappingKind($business);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.confirm');
        $counterpartyField = $kind === 'Shop' ? 'distributor_id' : 'shop_id';
        $input = $request->validate([
            $counterpartyField => $mapping === null ? 'prohibited' : 'required|uuid',
            'code' => 'required|string|size:32',
        ]);
        // The acting Initiator is `business`; the Recipient is the counterparty.
        $counterpartyId = $input[$counterpartyField] ?? null;
        $shop = $kind === 'Shop' ? $business : $counterpartyId;
        $distributor = $kind === 'Distributor' ? $business : $counterpartyId;

        return DB::transaction(function () use ($input, $shop, $distributor, $kind, $mapping, $actor): JsonResponse {
            $id = $this->consume($mapping, $shop, $distributor, 'pending_'.strtolower($kind), $input['code'], [
                'state' => 'Active', 'code_digest' => null, 'expires_at' => null,
            ]);
            if ($id === null) {
                return $this->unavailable();
            }
            $this->record($id, $actor, 'activated');

            return response()->json(['id' => $id, 'state' => 'Active']);
        });
    }

    /**
     * The Recipient refuses a Pending request (§4.4.1). The request is final and never
     * reopened, but a fresh request for the same pair remains allowed. Only the party that
     * did not initiate may reject.
     */
    public function reject(string $business, string $mapping): JsonResponse
    {
        return $this->decide($business, $mapping, 'Rejected', 'rejected', 'mapping.reject', initiator: false);
    }

    /**
     * The Initiator pulls back a Pending request before a decision (§4.4.2). A fresh
     * request for the same pair remains allowed. Only the party that initiated may withdraw.
     */
    public function withdraw(string $business, string $mapping): JsonResponse
    {
        return $this->decide($business, $mapping, 'Withdrawn', 'withdrawn', 'mapping.withdraw', initiator: true);
    }

    /**
     * Move a Pending request to a terminal request-state (Rejected or Withdrawn). Both
     * decisions are taken at the first pending stage (the request still awaits the
     * Recipient, `pending_<counterparty kind>`), where the Initiator may withdraw and the
     * Recipient may reject. The actor's role is resolved from `initiated_by`.
     */
    private function decide(string $business, string $mapping, string $state, string $action, string $permission, bool $initiator): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = $this->mappingKind($business);
        $actor = $this->access->requirePermission($business, $kind, $permission);

        return DB::transaction(function () use ($state, $action, $mapping, $business, $actor, $kind, $initiator): JsonResponse {
            // Both decisions act on the request's first pending stage. The row's state is named
            // after the Recipient (the side that owes the next decision) and is set by the
            // Initiator, so it cannot be derived from the acting side's kind. The mapping is
            // scoped by membership in either column and by being in either pending stage; the
            // actor's role (Initiator withdraws / Recipient rejects) is resolved from
            // `initiated_by`, which is what separates the two actions in the same state.
            $row = DB::table('distributor_shop_mappings')->where('id', $mapping)
                ->where(function ($query) use ($business): void {
                    $query->where('shop_id', $business)->orWhere('distributor_id', $business);
                })->where(function ($query): void {
                    $query->where('state', 'pending_shop')->orWhere('state', 'pending_distributor');
                })->lockForUpdate()->first(['id', 'state', 'initiated_by']);
            abort_if($row === null, 409);
            // The actor must hold the role that owns this decision: the Initiator withdraws,
            // the Recipient rejects.
            abort_unless(($row->initiated_by === $kind) === $initiator, 403);
            DB::table('distributor_shop_mappings')->where('id', $mapping)->update([
                'state' => $state, 'code_digest' => null, 'expires_at' => null, 'request_expires_at' => null,
                'rejected_at' => $action === 'rejected' ? now() : null,
                'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1'),
            ]);
            $this->record($mapping, $actor, $action);

            return response()->json(['id' => $mapping, 'state' => $state]);
        });
    }

    public function end(string $business, string $mapping): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = $this->mappingKind($business);
        abort_unless(in_array($kind, ['Shop', 'Distributor'], true), 403);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.end');

        return DB::transaction(function () use ($mapping, $business, $kind, $actor): JsonResponse {
            $endpoint = $kind === 'Shop' ? 'shop_id' : 'distributor_id';
            $issuer = $kind === 'Shop' ? 'pending_distributor' : 'pending_shop';
            $changed = DB::table('distributor_shop_mappings')->where('id', $mapping)->where($endpoint, $business)
                ->whereIn('state', [$issuer, 'Active', 'Suspended', 'Deactivated'])->update([
                    'state' => 'Terminated', 'code_digest' => null, 'expires_at' => null,
                    'request_expires_at' => null,
                    'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1'),
                ]);
            abort_unless($changed === 1, 409);
            $this->record($mapping, $actor, 'terminated');

            return response()->json(['id' => $mapping, 'state' => 'Terminated']);
        });
    }

    public function history(string $business, string $mapping): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = DB::table('business_identities')->where('id', $business)->value('kind');
        abort_unless(in_array($kind, ['Shop', 'Distributor'], true), 403);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.history.view');
        $endpoint = $kind === 'Shop' ? 'shop_id' : 'distributor_id';
        $type = DB::table('distributor_shop_mappings')->where('id', $mapping)
            ->where($endpoint, $business)->value('mapping_type');
        abort_if($type === null, 404);
        $records = DB::table('distributor_shop_mapping_history')->where('mapping_id', $mapping)
            ->orderByDesc('recorded_at')->orderByDesc('id')->simplePaginate(30, ['id', 'action', 'recorded_at']);
        $records->through(static function (object $entry): object {
            $entry->recorded_at = CarbonImmutable::parse($entry->recorded_at, 'UTC')->utc()->toISOString();

            return $entry;
        });
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $mapping,
            'action' => 'mapping.history_viewed', 'recorded_at' => now(),
            'details' => json_encode(['business_id' => $business, 'page' => $records->currentPage()], JSON_THROW_ON_ERROR),
        ]);

        return response()->json([
            ...$records->toArray(),
            'mapping_type' => ['code' => $type, ...config('mapping_types')[$type]],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The authorized mapped-operation catalogue for one mapping, as either participant may
     * view it. This is the §12.6 surface of the operation gate: only verbs and data fields
     * expressly authorized by Main Portal governance appear here, and an empty allowlist
     * authorizes nothing — a legal mapping confers zero access to unlisted data, the other
     * party's Parent Organization, or administration.
     */
    public function operations(string $business, string $mapping): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $kind = DB::table('business_identities')->where('id', $business)->value('kind');
        abort_unless(in_array($kind, ['Shop', 'Distributor'], true), 403);
        $actor = $this->access->requirePermission($business, $kind, 'mapping.history.view');
        $endpoint = $kind === 'Shop' ? 'shop_id' : 'distributor_id';
        abort_unless(DB::table('distributor_shop_mappings')->where('id', $mapping)
            ->where($endpoint, $business)->exists(), 404);
        $catalogue = $this->gate->catalogue($mapping);
        $type = DB::table('distributor_shop_mappings')->where('id', $mapping)->value('mapping_type');
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'actor_id' => $actor, 'subject_id' => $mapping,
            'action' => 'mapping.operations_viewed', 'recorded_at' => now(),
            'details' => json_encode(['business_id' => $business], JSON_THROW_ON_ERROR),
        ]);

        return response()->json(['id' => $mapping, 'mapping_type' => $type,
            'mapping' => $catalogue])->header('Cache-Control', 'no-store');
    }

    private function consume(?string $id, string $shop, ?string $distributor, string $state, string $code, array $next): ?string
    {
        $query = DB::table('distributor_shop_mappings')->where([
            'shop_id' => $shop, 'state' => $state, 'code_digest' => hash('sha256', $code),
        ])->when($id !== null, fn ($mappings) => $mappings->where('id', $id))
            ->when($distributor !== null, fn ($mappings) => $mappings->where('distributor_id', $distributor));
        // A Pending request not decided within its own validity is expired (§4.4). Lazy
        // expiry: before the per-code expires_at gate (which an undecided request may
        // outlive), look for a live request whose request_expires_at has passed and mark it
        // Expired, so a later attempt sees no live request. The per-code expires_at still
        // gates each secret exchange on its own.
        $expired = $query->clone()->where('request_expires_at', '<=', now())->first(['id']);
        if ($expired !== null) {
            DB::table('distributor_shop_mappings')->where('id', $expired->id)->update([
                'state' => 'Expired', 'code_digest' => null, 'expires_at' => null, 'request_expires_at' => null,
                'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1'),
            ]);

            // Report the expiry to the caller as a null id rather than aborting: the caller
            // is inside a transaction, and aborting here would roll the Expired state back
            // with it. The caller returns 422 once the transaction has committed.
            return null;
        }
        $matches = $query->clone()->where('expires_at', '>', now())->limit(2)->get(['id', 'distributor_id']);
        abort_unless($matches->count() === 1, 422, 'Mapping code is unavailable or invalid.');
        $mapping = $matches->first();
        $this->requireActiveEndpoints($shop, $mapping->distributor_id);
        abort_unless($query->where('id', $mapping->id)->where('expires_at', '>', now())
            ->update([...$next, 'lifecycle_operation_id' => null, 'lifecycle_version' => DB::raw('lifecycle_version + 1')]) === 1,
            422, 'Mapping code is unavailable or invalid.');

        return $mapping->id;
    }

    /** 422 for an expired request, returned after its Expired transition has committed. */
    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => 'Mapping code is unavailable or invalid.'], 422);
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

    /**
     * Enforce the duplicate-pair ban at the application level as well as through the
     * live-pair unique index: while a Pending, Active or Deactivated mapping exists for a
     * pair, a new request is refused (§4.4). A decided request (Rejected/Withdrawn/Expired)
     * or a Terminated mapping never blocks a fresh request.
     */
    private function requireFreshPair(string $shop, string $distributor): void
    {
        abort_unless(DB::table('distributor_shop_mappings')->where('shop_id', $shop)->where('distributor_id', $distributor)
            ->whereIn('state', ['pending_distributor', 'pending_shop', 'Active', 'Deactivated'])->doesntExist(), 409);
    }

    /**
     * Resolve the acting endpoint's kind (Shop or Distributor) from the record, so the
     * Initiator/Recipient role is never assumed from a hardcoded kind.
     */
    private function mappingKind(string $business): string
    {
        $kind = DB::table('business_identities')->where('id', $business)->value('kind');
        abort_unless(in_array($kind, ['Shop', 'Distributor'], true), 403);

        return $kind;
    }

    private function counterpartyKind(string $kind): string
    {
        return $kind === 'Shop' ? 'Distributor' : 'Shop';
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
