<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationAccessDelegation;
use App\Authorization\OrganizationOnboardingAccess;
use App\Authorization\OrganizationTimePresentationAccess;
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
        OrganizationTimePresentationAccess $timePresentation,
    ): JsonResponse {
        $user = Auth::id();
        abort_if($user === null, 401);
        $entries = array_filter(PermissionCatalogue::mainPortal());
        $grants = $portal->grantsFor((string) $user)->whereIn('permission', array_keys($entries))->pluck('permission')->all();
        $workspaces = array_values(array_intersect_key($entries, array_fill_keys($grants, true)));

        $organization = $onboarding->contextFor((string) $user);
        $recovery = $onboarding->contextFor((string) $user, OrganizationOnboardingAccess::RECOVERY);
        $provider = $providers->directoryFor((string) $user);
        $timeSettings = $timePresentation->workspaceContext((string) $user);

        return response()->json([
            'administration' => $workspaces,
            'mapping_contexts' => $business->mappingContexts(),
            'delegations' => $delegation->summariesFor((string) $user),
            ...($organization === null ? [] : ['organization_onboarding' => $organization]),
            ...($recovery === null ? [] : ['organization_recovery' => $recovery]),
            ...($timeSettings === null ? [] : ['organization_time_presentation' => $timeSettings]),
            ...($provider === null ? [] : ['provider_directory' => $provider]),
        ])->header('Cache-Control', 'no-store');
    }
}
