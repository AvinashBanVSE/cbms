<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\AuthorizationEvaluator;
use App\Authorization\CustomerPermissionGrants;
use App\Authorization\PermissionCatalogue;
use App\Authorization\ProviderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated post-login context (plan §6 / Stream 1).
 *
 * One answer to "who am I, what may I do, and where" — complementing the existing three-key
 * `workspace-access` payload with identity, account state, denies, entitlements and, per
 * business, an evaluator decision carrying a reason and the catalogue version. Read-only and
 * additive; nothing is enforced from it yet.
 */
final class AuthorizationContextController
{
    public function __invoke(
        AuthorizationEvaluator $evaluator,
        ProviderAccess $providers,
    ): JsonResponse {
        $user = Auth::id();
        abort_if($user === null, 401);
        $id = (string) $user;

        $account = ProviderAccess::sessionAccount($id);
        $identity = DB::table('users')->where('id', $id)->first(['id', 'code', 'email']);

        $businesses = CustomerPermissionGrants::forUser($id)
            ->distinct()->orderBy('business_id')->pluck('business_id')->all();

        $decisions = [];
        foreach ($businesses as $businessId) {
            $decisions[] = $evaluator->decide($id, $businessId)->toArray();
        }

        return response()->json([
            'identity' => $identity,
            'account_state' => $account?->state,
            'session_valid' => ($account?->state ?? null) === 'Active',
            'decisions' => $decisions,
            'provider_directory' => $providers->directoryFor($id),
            'catalogue_version' => PermissionCatalogue::revision(),
        ])->header('Cache-Control', 'no-store');
    }
}
