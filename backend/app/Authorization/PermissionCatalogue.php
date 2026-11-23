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
        'business_structure.closure_policy' => null,
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
        // Both kinds share the same verbs: either may initiate, verify, confirm, end,
        // reject or withdraw, and either may view history. The role a party holds on a
        // given request (Initiator vs. Recipient) is derived from the mapping record, so
        // no privilege asymmetry is encoded here (§4.2).
        'Shop' => ['mapping.initiate', 'mapping.verify', 'mapping.confirm', 'mapping.end',
            'mapping.reject', 'mapping.withdraw', 'mapping.history.view'],
        'Distributor' => ['mapping.initiate', 'mapping.verify', 'mapping.confirm', 'mapping.end',
            'mapping.reject', 'mapping.withdraw', 'mapping.history.view'],
    ];

    /**
     * The explicitly permitted workflow that governs first-party client activation (§6D.7).
     * Authorized against a Tenant's own Parent Organization, as a Tenant Owner/Admin action;
     * it never confers a business permission and stays separate from action authorization.
     */
    public const TENANT_ADMIN = ['clients.activate'];
}
