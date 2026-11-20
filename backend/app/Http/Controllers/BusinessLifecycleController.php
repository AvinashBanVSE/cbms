<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Domain\BusinessLifecycle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BusinessLifecycleController
{
    public function __construct(private readonly MainPortalAccess $access, private readonly BusinessLifecycle $lifecycle) {}

    public function show(string $business): JsonResponse
    {
        $this->access->requirePermission('business_structure.lifecycle');
        $entity = DB::table('business_identities')->where('id', $business)->whereIn('kind', BusinessLifecycle::KINDS)->first();
        abort_if($entity === null, 404);

        return response()->json([
            'state' => $entity->operational_state, 'version' => $entity->lifecycle_version,
            'allowed_transitions' => BusinessLifecycle::TRANSITIONS[$entity->operational_state] ?? [],
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

        return response()->json($this->lifecycle->transition($business, (string) Auth::id(), $input));
    }
}
