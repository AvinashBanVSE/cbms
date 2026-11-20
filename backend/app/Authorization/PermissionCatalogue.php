<?php

declare(strict_types=1);

namespace App\Authorization;

final class PermissionCatalogue
{
    /**
     * Workspace entry permissions are distinct from permissions for the actions inside it.
     */
    public const MAIN_PORTAL = [
        'service_catalogue.view' => 'services',
        'service_catalogue.create' => null,
        'business_structure.view' => 'businesses',
        'business_structure.create' => null,
        'business_identity.verify' => 'identities',
        'business_structure.lifecycle' => null,
        'service_provider.migrate' => null,
        'organization_users.manage' => 'users',
        'commercial.manage' => 'commercial',
        'organization_roles.approval_policy' => null,
        'provider_users.manage' => 'provider_users',
        'audit_trail.view' => 'audit',
        'global_business_settings.view' => 'business_settings',
        'global_business_settings.manage' => null,
    ];

    public const MAPPING_BY_BUSINESS = [
        'Shop' => ['mapping.initiate', 'mapping.confirm', 'mapping.end', 'mapping.history.view'],
        'Distributor' => ['mapping.verify', 'mapping.end', 'mapping.history.view'],
    ];
}
