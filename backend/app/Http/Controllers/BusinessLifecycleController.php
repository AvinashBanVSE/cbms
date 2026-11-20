<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Approval\BusinessClosureApproval;
use App\Authorization\MainPortalAccess;
use App\Domain\BusinessLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BusinessLifecycleController
{
    public function __construct(private readonly MainPortalAccess $access, private readonly BusinessLifecycle $lifecycle, private readonly BusinessClosureApproval $closure) {}

    public function show(string $business): JsonResponse
    {
        $this->access->requirePermission('business_structure.lifecycle');
        $entity = DB::table('business_identities')->where('id', $business)->whereIn('kind', BusinessLifecycle::KINDS)->first();
        abort_if($entity === null, 404);

        return response()->json([
            'state' => $entity->operational_state, 'version' => $entity->lifecycle_version,
            'allowed_transitions' => BusinessLifecycle::TRANSITIONS[$entity->operational_state] ?? [],
            'can_configure_closure' => $this->access->allows((string) Auth::id(), 'business_structure.closure_policy'),
        ])->header('Cache-Control', 'no-store');
    }

    public function transition(Request $request, string $business): JsonResponse
    {
        $this->access->requirePermission('business_structure.lifecycle');
        $input = $request->validate([
            'operation_id' => 'required|uuid',
            'expected_version' => 'required|integer|min:0',
            'state' => 'required|string|max:32',
            'verification_confirmed' => 'sometimes|boolean',
            'reason' => 'required|string|max:500',
        ]);

        $actor = (string) Auth::id();
        if ($input['state'] === 'Closed') {
            $input['expected_version'] = (int) $input['expected_version'];
            $result = $this->closure->request($business, $actor, $input);

            return response()->json($result, ($result['status'] ?? null) === 'pending' ? 202 : 200);
        }

        return response()->json($this->lifecycle->transition($business, $actor, $input));
    }

    public function closurePolicy(Request $request, string $business): JsonResponse
    {
        $this->access->requirePermission('business_structure.closure_policy');
        $input = $request->validate([
            'reviewers' => 'present|array|list|max:10',
            'reviewers.*' => 'required|distinct|regex:/^[A-Z]{2}[0-9]{4}$/D',
            'expiry_minutes' => 'nullable|integer|between:1,525600',
        ]);
        $this->closure->configure($business, (string) Auth::id(), $input);

        return response()->json(['reviewer_count' => count($input['reviewers'])]);
    }

    public function pendingClosures(string $business): JsonResponse
    {
        $this->access->requirePermission('business_structure.lifecycle');
        abort_unless(DB::table('business_identities')->where('id', $business)->whereIn('kind', BusinessLifecycle::KINDS)->exists(), 404);
        $approvals = DB::table('business_closure_approvals')->where(['business_id' => $business, 'status' => 'pending'])
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')->simplePaginate(30, ['id', 'command', 'expires_at'])
            ->through(static function (object $row): array {
                $command = json_decode($row->command, true, flags: JSON_THROW_ON_ERROR);

                return [
                    'id' => $row->id, 'reason' => $command['reason'],
                    'expires_at' => $row->expires_at === null ? null : CarbonImmutable::parse($row->expires_at)->toISOString(),
                ];
            });

        return response()->json($approvals)->header('Cache-Control', 'no-store');
    }

    public function decideClosure(Request $request, string $business, string $approval): JsonResponse
    {
        $this->access->requirePermission('business_structure.lifecycle');
        $input = $request->validate(['decision' => 'required|in:approve,reject']);
        $result = $this->closure->decide($business, $approval, (string) Auth::id(), $input['decision']);

        if (in_array($result['status'], ['expired', 'stale'], true)) {
            return response()->json([...$result, 'message' => 'This closure request is no longer valid.'], 409);
        }

        return response()->json($result);
    }
}
