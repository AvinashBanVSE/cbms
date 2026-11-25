# Authorization implementation status — 2026-11-24

The user approved all policy decisions and authorized implementation. Delivery is staged so that each authorization change is reviewable and verified. No deployment or production migration has been performed.

## Completed foundation increment

- Database permission catalogue and entity applicability, preserving existing operation codes and grants.
- Protected catalogue read API and catalogue-derived assignment validation.
- Contract verification repaired with a committed source checksum manifest; absent frontend references reconciled.

## Completed entity-role backend increment

- Existing role tables extended to support Main Portal, Sub-Portal, Organization, Distributor and Shop, with independent names/contents per entity and versioned governed roles.
- Explicit grant-authority limits separate from use permissions. Scoped local role managers can create and assign only eligible permissions they may grant. Existing Main Portal administrative authority governs initialization without silently assigning new powers during migration.
- Entity role APIs for listing, creation, versioned editing, assignment, immediate reasoned emergency revocation, approval inbox/decisions and independent template-style copy. Assigned roles must be revoked before content edits in this increment.
- Separate current association/scope checks and account/lifecycle restrictions. Existing customer scopes are retained. Explicit platform/provider self scopes mirror already-issued authority and are recorded at normal bootstrap/provisioning. Role assignment creates no scope or membership.
- Privileged assignment requires independent review. Existing configurable assignment stages, sequential/parallel counts, reviewer/beneficiary exclusions, expiry, current authority rechecks, audit events and snapshot invalidation are implemented. Management approval cannot be disabled by changing its catalogue flag.
- Role grants integrated with existing portal, provider, mapping, organization onboarding/presentation and client-administration checks. Eligibility is applied in SQL without per-entity permission-fetch loops or a stale authorization cache.
- Governed roles cannot be assigned through the legacy endpoint. Legacy rows and existing assignments remain intact; no bulk conversion or privilege expansion is performed.
- Business-scoped direct user denies are persisted and enforced against governed role grants, with optional expiry, audited creation/removal and manager-only administration.

See entity-role-api.md for payloads, boundaries and migration details.

## Validation

PHP 8.4 with locked Composer dependencies; SQLite automated suite: **131 tests, 1,918 assertions passed**. PCOV statement coverage **91.88% (4,163 / 4,531)**; repository 90% gate passes. Pint, diff checks and refreshed contract verification pass.

Security coverage includes all five entity kinds, same-name independence, grant ceilings, use-versus-grant distinction, tenant isolation, missing/removed scope, account state changes, legacy bypass rejection, stale role versions, assigned-role edit rejection, self/beneficiary approval rejection, distinct votes and sequential stages, expiry/rejection, repeated execution, policy/catalogue changes invalidating requests, reviewer-only inboxes, independent copies and client-administration role integration. A populated pre-upgrade database test verifies legacy permissions/assignments/scopes are preserved. PostgreSQL concurrency/load testing and frontend E2E are not claimed by this SQLite suite.

Review repairs in this increment: a scope-aware deny-exception carve-out (a record-scoped exception no longer waives the deny for every record), a business-id in the evaluator decision cache key (two entities with equal versions can no longer collide), a catalogue revision token that moves on any non-maximum permission edit, a fail-closed eligibility gate in the central evaluator (a direct grant alone cannot authorize an ineligible context), version invalidation on grant/membership writers, and a frontend approvals screen aligned to the real approval API contract (true decision status, not a blanket "applied").

## Remaining approved work

Approved deny exceptions beyond the current business-scoped user deny model; selected/snapshot/excluded/future entity scope selectors; owner-linked/purpose-limited cross-entity policy evaluation; full policy precedence and configuration editors; atomic edits of assigned roles through an approval workflow; governed permission-catalogue writes; frontend administration; queued-work/caching integration; full shadow comparison and controlled cutover. Existing portal-wide administrative operations and provider-directory meanings have not been narrowed into per-record scope by this increment.

## Migration and rollback boundary

Deploy migrations 000052 and 000053 before serving this code. A missing catalogue fails rather than reverting to a hardcoded list. Take a database backup and compare existing role/grant/scope records before/after. Migration 000053 refuses automatic down migration: dropping its authority/restriction records or returning to code that ignores them is not a safe live rollback. Use a reviewed forward correction or reconcile all intervening revocations before coordinated restoration. No SQL permission edits should substitute for the pending governed catalogue editor.
