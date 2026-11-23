#!/usr/bin/env bash

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
reference="$repo_root/docs/agent-contracts.md"

if [[ ! -f "$reference" ]]; then
    echo "agent contracts: missing docs/agent-contracts.md" >&2
    exit 1
fi

# Keep this list limited to sources cited by the high-risk reference. A new durable
# contract must add its authoritative source here in the same change.
contract_sources=(
    'backend/app/Accounts/LoginIdentity.php'
    'backend/app/Accounts/SetupDelivery.php'
    'backend/app/Accounts/UserCode.php'
    'backend/app/Authorization/MainPortalAccess.php'
    'backend/app/Authorization/OrganizationAccessDelegation.php'
    'backend/app/Authorization/PermissionCatalogue.php'
    'backend/app/Authorization/MappingPermissions.php'
    'backend/app/Authorization/CustomerBusinessAccess.php'
    'backend/app/Authorization/OrganizationOnboardingAccess.php'
    'backend/app/Authorization/OrganizationTimePresentationAccess.php'
    'backend/app/Http/Controllers/PermissionCatalogueController.php'
    'backend/database/migrations/2026_11_24_000052_create_permission_catalogue.php'
    'backend/app/Authorization/ProviderAccess.php'
    'backend/app/Clients/ClientActivation.php'
    'backend/app/Domain/BusinessLifecycle.php'
    'backend/app/Domain/BusinessStructure.php'
    'backend/app/Http/Controllers/AccountRecoveryController.php'
    'backend/app/Http/Controllers/BusinessLifecycleController.php'
    'backend/app/Http/Controllers/BusinessMappingController.php'
    'backend/app/Http/Controllers/BusinessStructureController.php'
    'backend/app/Http/Controllers/ClientActivationController.php'
    'backend/app/Http/Controllers/InstallationController.php'
    'backend/app/Http/Controllers/UserLoginIdentifierController.php'
    'backend/app/Http/Controllers/ReservedUsernameController.php'
    'backend/app/Http/Controllers/OrganizationCredentialController.php'
    'backend/app/Http/Controllers/WorkspaceAccessController.php'
    'backend/app/Http/Middleware/ValidateOrganizationSession.php'
    'backend/app/Mapping/MappingGate.php'
    'backend/app/Validation/PermanentPassword.php'
    'backend/bootstrap/app.php'
    'backend/composer.json'
    'backend/routes/api.php'
    'backend/routes/browser.php'
    'backend/tools/check-coverage.php'
    'frontend/next.config.ts'
    'frontend/src/views/pages/onboarding/portal-setup/index.tsx'
    'frontend/package.json'
    'frontend/src/views/pages/auth/login/login-v3/index.tsx'
    'frontend/src/views/pages/auth/login/login-v3/login-v3-form.tsx'
)

manifest="$repo_root/docs/agent-contracts.sha256"
if [[ "${1:-}" == "--refresh" ]]; then
    : > "$manifest"
    for source in "${contract_sources[@]}"; do
        if [[ -f "$repo_root/$source" ]]; then
            (cd "$repo_root" && sha256sum "$source") >> "$manifest"
        else
            echo "Missing contract source: $source" >&2
            exit 1
        fi
    done
    echo "agent contracts: manifest refreshed; review and commit it with the sources"
    exit 0
fi
if [[ ! -f "$manifest" ]]; then
    echo "agent contracts: missing committed source manifest" >&2
    exit 1
fi
for source in "${contract_sources[@]}"; do
    if ! grep -Fqx "$(cd "$repo_root" && sha256sum "$source")" "$manifest"; then
        echo "agent contracts: stale: $source" >&2
        exit 1
    fi
done
echo "agent contracts: fresh"
