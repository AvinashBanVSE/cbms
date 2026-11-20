<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationAccessDelegation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class WorkspaceAccessController
{
    private const WORKSPACES = [
        'services' => 'service_catalogue.view',
        'businesses' => 'business_structure.view',
        'users' => 'organization_users.manage',
        'commercial' => 'commercial.manage',
        'audit' => 'audit_trail.view',
    ];

    public function __invoke(MainPortalAccess $portal, CustomerBusinessAccess $business, OrganizationAccessDelegation $delegation): JsonResponse
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        $grants = $portal->grantsFor((string) $user)->whereIn('permission', array_values(self::WORKSPACES))->pluck('permission')->all();
        $workspaces = array_keys(array_intersect(self::WORKSPACES, $grants));

        return response()->json([
            'administration' => $workspaces,
            'mapping_contexts' => $business->mappingContexts(),
            'delegations' => $delegation->summariesFor((string) $user),
        ])->header('Cache-Control', 'no-store');
    }
}
