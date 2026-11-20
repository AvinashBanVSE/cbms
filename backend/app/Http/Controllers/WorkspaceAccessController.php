<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationAccessDelegation;
use App\Authorization\OrganizationOnboardingAccess;
use App\Authorization\PermissionCatalogue;
use App\Authorization\ProviderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class WorkspaceAccessController
{
    public function __invoke(
        MainPortalAccess $portal,
        CustomerBusinessAccess $business,
        OrganizationAccessDelegation $delegation,
        OrganizationOnboardingAccess $onboarding,
        ProviderAccess $providers,
    ): JsonResponse {
        $user = Auth::id();
        abort_if($user === null, 401);
        $entries = array_filter(PermissionCatalogue::MAIN_PORTAL);
        $grants = $portal->grantsFor((string) $user)->whereIn('permission', array_keys($entries))->pluck('permission')->all();
        $workspaces = array_values(array_intersect_key($entries, array_fill_keys($grants, true)));

        $organization = $onboarding->contextFor((string) $user);
        $provider = $providers->directoryFor((string) $user);

        return response()->json([
            'administration' => $workspaces,
            'mapping_contexts' => $business->mappingContexts(),
            'delegations' => $delegation->summariesFor((string) $user),
            ...($organization === null ? [] : ['organization_onboarding' => $organization]),
            ...($provider === null ? [] : ['provider_directory' => $provider]),
        ])->header('Cache-Control', 'no-store');
    }
}
