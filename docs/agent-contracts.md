# CBMS high-risk contracts

This is a source-linked working reference for coding agents, not a design proposal or a repository map. It records only non-obvious contracts that cross modules or stacks and are costly to reconstruct incorrectly.

**Verified source tree:** `9284f4c9223fc3e4a7ac0acef7cac14bbdd5345f`

Run `scripts/check-agent-contracts.sh` before relying on this file. A stale result means that one or more cited contract sources changed after the tree above; inspect only the paths reported by the checker and reconcile the affected statements. Source code always wins.

## 1. Identity and authority boundaries

- "Customer" in code means an account belonging to an Organization tenant; it is not an end buyer or shopper. Main Portal, Sub-Portal provider, and Organization customer authority are deliberately separate. A Main Portal grant is unusable when the same user has a provider or customer account; provider directory access is unusable when the user has a customer account. (`backend/app/Authorization/MainPortalAccess.php`, `backend/app/Authorization/ProviderAccess.php`)
- Workspace visibility is not action authorization. `workspace-access` exposes only permitted workspace entry keys plus mapping/delegation/onboarding/provider contexts; controllers must still enforce their action permission. `null` entries in the Main Portal catalogue are action-only and must not become menu entries. (`backend/app/Authorization/PermissionCatalogue.php`, `backend/app/Http/Controllers/WorkspaceAccessController.php`)
- Organization access has two paths: Main Portal authority with `organization_users.manage`, or an active customer delegation. A delegation is valid only for the same tenant, a direct child business, an active recipient account with active association and scope, a current Main Portal issuer, its time window, and every requested allowed action. (`backend/app/Authorization/OrganizationAccessDelegation.php`)

## 2. Installation, sign-in, and session lifecycle

- Installation is a strict one-way state machine: `setup_required -> owner_required -> ready`. Setup creates the UUIDv7 Main Portal; owner creation creates the owner/user, grants every Main Portal catalogue permission, and returns the generated six-character user code. Both writes require `X-Setup-Code`, whose value is at least 32 characters and whose SHA-256 digest must match the 64-character configured digest. (`backend/routes/api.php`, `backend/app/Http/Controllers/InstallationController.php`)
- Permanent passwords are at least 15 characters, at most 72 bytes, contain no null byte, and must pass Laravel's uncompromised-password rule. Do not replace this with frontend-only validation. (`backend/app/Validation/PermanentPassword.php`)
- Normal sign-in requires the globally unique uppercase User Code (`AA0000` shape), a password, and exactly one enabled identifier: email, username, or Indian ten-digit mobile number. The server resolves that identifier only against the user selected by the code, never across accounts. Legacy API clients may still send the original `email` field instead of `identifier`. User login-method preferences default to email enabled; username and mobile remain disabled until configured through the authenticated password-confirmed endpoint `GET/PUT /api/v1/me/login-identifiers`. An update cannot remove the user's final enabled login method. A mobile number saved as a login alias is not proof of SMS ownership. Outcomes are `204` success, `202/authenticator_required`, or `202/authenticator_enrollment_required`; preserve existing login/MFA throttling, sessions, and audit behavior. (`backend/routes/browser.php`, `backend/app/Accounts/LoginIdentity.php`, `backend/app/Http/Controllers/UserLoginIdentifierController.php`, `backend/database/migrations/2026_11_23_000050_add_user_login_identifiers.php`, `frontend/src/views/pages/auth/login/login-v3/login-v3-form.tsx`)
- Every web request runs the organization-session validator. Account state/session-version changes, authenticator-version changes, required re-enrollment, and a pending recovery setup invalidate the session. Features that suspend/recover/revoke accounts must preserve those version changes rather than relying on UI logout. (`backend/bootstrap/app.php`, `backend/app/Http/Middleware/ValidateOrganizationSession.php`, `backend/app/Authorization/ProviderAccess.php`)
- User-code allocation is globally serialized through the installation row and checks the shared `users` table. Provisioning code must call it inside its transaction; codes are not scoped per tenant or account type. (`backend/app/Accounts/UserCode.php`)

## 3. Setup and recovery proof flow

- Recovery initiation is an authenticated administrative action, not a public forgot-password flow. Organization recovery requires its recovery authority; provider recovery requires `provider_users.manage`. Replacing an authenticator additionally requires Main Portal authority and explicit identity confirmation. (`backend/routes/browser.php`, `backend/app/Http/Controllers/AccountRecoveryController.php`)
- Setup/recovery delivery contains a user code plus a temporary password and/or numeric OTP. It deliberately sends no URL or clickable recovery link. Delivery refuses log/array/failover/round-robin transports and verifies that the plaintext proof still matches the stored, unexpired hash before sending. (`backend/app/Accounts/SetupDelivery.php`)
- Proof verification happens only while signed out. A valid temporary password/OTP is consumed into a five-minute session proof; three failed verification attempts block verification for an hour. Completion consumes that proof, applies the permanent-password rules/history, activates only a first-time pending account, then invalidates the setup session. (`backend/app/Http/Controllers/OrganizationCredentialController.php`)

## 4. Business structure, lifecycle, and mappings

- The only managed business kinds are Main Portal, Sub-Portal, Organization, Distributor, and Shop. Distributor and Shop must have a direct Organization parent and inherit the parent's complete owner set; child ownership cannot diverge. An Organization must select an active Main Portal/Sub-Portal provider and becomes a tenant. (`backend/app/Http/Controllers/BusinessStructureController.php`, `backend/app/Domain/BusinessStructure.php`)
- Operational transitions are versioned and idempotent by caller-supplied `operation_id`. Activation from Pending Verification needs explicit verification confirmation; a child can activate only while its parent Organization is active. Suspended/Deactivated state cascades to affected children and mappings, with guarded restoration only when versions and causal operation still match. Closing an Organization requires all direct children Closed and all affected mappings Terminated. (`backend/app/Domain/BusinessLifecycle.php`, `backend/app/Http/Controllers/BusinessLifecycleController.php`)
- A Distributor-Shop mapping is a two-party, two-secret handshake that is symmetric: either a Shop or a Distributor may initiate, and the acting side is recorded in `initiated_by`. The first pending stage is named after the Recipient (the side that owes the next decision), so `pending_distributor` means the Distributor owes the next action and `pending_shop` the mirror image. The Recipient consumes the first 32-character secret and receives a replacement (stage moves to `pending_<initiator kind>`), then the Initiator consumes that secret to activate. Secrets are returned once, stored only as SHA-256 digests, and expire; a Pending request also has its own `request_expires_at` validity, past which it is lazily marked `Expired`. Both endpoints and both parent Organizations must be Active. A Pending request may be `Rejected` by the Recipient or `Withdrawn` by the Initiator (both recorded, and neither blocks a fresh request for the same pair, as does `Expired`/`Terminated`). A live pair (Pending/Active/Deactivated) is barred by an application check plus a partial unique index. A legal mapping grants no unlisted business/data permission. (`backend/app/Http/Controllers/BusinessMappingController.php`, `backend/app/Mapping/MappingGate.php`)

## 5. First-party client activation is not authorization

- Desktop activation codes are issued by an active tenant customer with `clients.activate`, are tenant-bound, store only a digest, and are one-time. Consumption is intentionally sessionless but atomically enforces the governed desktop-instance capacity; with no published capacity, activation is denied. (`backend/app/Clients/ClientActivation.php`, `backend/app/Http/Controllers/ClientActivationController.php`)
- Mobile activation requires a signed-in active customer with `clients.activate` and keeps exactly one active mobile instance per user. Desktop or mobile activation tokens identify an active client instance only; they confer no business permission, so later operations must still authenticate the user and authorize tenant/business access. (`backend/app/Clients/ClientActivation.php`)

## 6. Frontend/backend bridge and page navigation

- Portal API traffic remains readable under `/api/v1/*`. Stateful body requests first obtain `/api/v1/csrf`, send `X-CSRF-TOKEN`, include credentials, and use `no-store`; setup additionally sends `X-Setup-Code`. Do not route API calls through page navigation or weaken CSRF/session behavior. (`frontend/src/lib/cbms-api.ts`, `backend/routes/browser.php`)
- Browser page URLs are opaque for product UX, not security. Page keys are resolved server-side from an HMAC-signed, HTTP-only, SameSite=Strict cookie; entries use UUIDv7-shaped IDs, expire after 15 minutes, and are pruned to eight. Production requires `OPAQUE_NAVIGATION_SECRET`. (`frontend/src/lib/opaque-navigation.ts`, `frontend/src/app/navigation/route.ts`)
- All client page transitions must request a fresh opaque ID from `/navigation`; readable legacy paths are redirects only. Invalid/expired IDs redirect through `/` to a fresh login ID. Authentication/authorization still comes from Laravel `workspace-access`, not from possession of an opaque ID. `/_next/*` and `/api/v1/*` are outside this page-routing mechanism. (`frontend/src/lib/client-navigation.ts`, `frontend/src/proxy.ts`, `frontend/src/app/[opaqueId]/page.tsx`, `frontend/src/components/auth/AuthenticatedLayout.tsx`)

## 7. Verification truth

- Backend statement coverage has an executable 90% floor: `composer test:coverage` creates Clover output and fails below 90%. (`backend/composer.json`, `backend/tools/check-coverage.php`)
- The frontend currently defines build, type-check, lint, and Playwright E2E commands, but no executable percentage-coverage gate. Do not claim frontend 90% coverage from passing E2E alone. (`frontend/package.json`, `frontend/playwright.config.ts`)

## Refreshing the verified tree

The agent changing a watched source owns this refresh in the same change:

1. Reconcile every affected statement against the source diff. Update prose/citations only if semantics changed.
2. Stage all source and reference changes except the `Verified source tree` line.
3. Run `git write-tree`; this records a tree containing the reviewed source state.
4. Replace the hash in `Verified source tree` with that output and stage this file. The final tree may differ because this line changed; that is expected because the checker compares only contract sources.
5. Run `scripts/check-agent-contracts.sh` and the relevant product tests before committing.

Never refresh the hash without reviewing the paths reported by the checker.
