#!/usr/bin/env bash

set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
reference="$repo_root/docs/agent-contracts.md"

if [[ ! -f "$reference" ]]; then
    echo "agent contracts: missing docs/agent-contracts.md" >&2
    exit 1
fi

verified_tree="$(sed -nE 's/^\*\*Verified source tree:\*\* `([0-9a-f]{40})`$/\1/p' "$reference")"
if [[ -z "$verified_tree" ]] || ! git -C "$repo_root" cat-file -e "$verified_tree^{tree}" 2>/dev/null; then
    echo "agent contracts: invalid or unavailable Verified source tree" >&2
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
    'frontend/package.json'
    'frontend/playwright.config.ts'
    'frontend/src/app/[opaqueId]/page.tsx'
    'frontend/src/app/navigation/route.ts'
    'frontend/src/components/auth/AuthenticatedLayout.tsx'
    'frontend/src/lib/cbms-api.ts'
    'frontend/src/lib/client-navigation.ts'
    'frontend/src/lib/opaque-navigation.ts'
    'frontend/src/proxy.ts'
    'frontend/src/views/pages/auth/login/login-v3/index.tsx'
    'frontend/src/views/pages/auth/login/login-v3/login-v3-form.tsx'
)

mapfile -t changed < <(git -C "$repo_root" diff --name-only "$verified_tree" -- "${contract_sources[@]}")

if (( ${#changed[@]} == 0 )); then
    echo "agent contracts: fresh ($verified_tree)"
    exit 0
fi

echo "agent contracts: stale; review only these changed contract sources:" >&2
printf '  - %s\n' "${changed[@]}" >&2
echo "Reconcile docs/agent-contracts.md, refresh its Verified source tree, and rerun this check." >&2
exit 1
