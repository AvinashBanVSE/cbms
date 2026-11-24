# Branch integration review — 2026-11-24

Reviewed main f73add47938fb41a307093c94eddd021510c3d37 and authorization head abc20f03c2513e6726080a405225a4ce752fcc32.

- development/foundation and frontend/portal-setup are already ancestors of main; no additional code is needed from either.
- PR #1 is already merged. PR #2 contains the next governed-role increment and conflicts with the earlier catalogue merge.
- Resolved nine conflicting files: ProviderAccess, InstallationController, browser routes, PermissionCatalogueTest, contract checksums/checker and three authorization planning/status documents. Source diffs confirm the newer role-aware behavior preserves the existing catalogue behavior.
- Retained main's architecture-map.html and ownership-mutation-proposal.md. Corrected their obsolete PHP catalogue references to the database-backed implementation. Ownership transfer remains an unapproved, unimplemented proposal.
- The combined application source matches the previously reviewed authorization implementation. Only main's two documentation additions and this integration record differ from that feature tree.
- Integrated verification: 108 tests, 1,822 assertions; 92.10% statement coverage; required 90% gate, Pint, source-contract checker and diff checks pass. SQLite/PHP 8.4 suite; no claim of PostgreSQL load/concurrency or frontend E2E testing. No frontend application files changed in this integration.

Existing grants/scopes remain preserved by the tested migration. The 000053 migration refuses destructive automatic rollback. Production deployment/migrations are separate from merging source. Direct Deny, broader scope selectors, full central-policy cutover and frontend role administration remain pending as documented in authorization-implementation-status.md.
