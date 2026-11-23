# Authorization implementation status — 2026-11-24

The user approved all policy decisions and authorized implementation. Delivery remains staged so that each authorization change is reviewable and verified. No deployment or production migration has been performed.

## Completed foundation increment

- Database-backed catalogue: 31 unique permission definitions with separate entity applicability. Existing operation codes and workspace mappings are preserved. Roles/grants/scopes are not copied, widened or reassigned by the migration.
- Existing portal, mapping, organization onboarding/presentation, provider-directory and client-activation checks consult active catalogue definitions. Mapping assignment validation derives its size from eligible database permissions instead of the literal seven. An unassignable definition cannot be added through mapping assignment validation; existing grants continue until revoked or the definition is inactive.
- Protected read endpoint: GET /api/v1/authorization/permissions requires existing organization_users.manage authority. No permission-editing endpoint is exposed before the approved approval/grant-governance workflow is implemented.
- Contract checker uses a committed source checksum manifest instead of an unpublished Git tree. Removed absent frontend references and reconciled the current template frontend description. Refresh is explicit and owned by the source-changing agent.
- Policy ratification recorded. The existing architecture is retained during this increment; this is not a complete central evaluator or entity role cutover.

## Validation

PHP 8.4, locked Composer dependencies, 94 tests and 1,665 assertions pass. New tests cover entity applicability isolation, assignment eligibility, permission retirement, endpoint authorization, unknown/wrong-context grants and migration grant preservation. PCOV statement coverage: 91.81% (3,463 / 3,772); repository 90% gate passes. Pint and diff whitespace checks pass. Contract checker tested in an isolated Git repository without historic Git objects, including mutated and missing watched sources.

## Remaining approved work

Complete schema and migration mapping for independent roles in all five entity contexts; explicit scope selectors and exclusions; direct deny and approved expiry; role/scope grant ceilings; versioned central evaluation; required approval escalation and template copy; configuration governance and frontend administration; parity/shadow comparison, cache/job invalidation and controlled cutover. Existing role administration remains portal-controlled until that stage is implemented and verified. No dynamic scopes or direct-deny user controls are enabled by this increment.

## Migration and rollback boundary

Deploy the additive catalogue migration before serving this code. A missing catalogue fails rather than falling back to a hardcoded list. Take the normal database backup and compare existing grant/role/scope counts before/after. Down migration removes only catalogue tables, but must never be used as a live authorization rollback: older code would ignore any catalogue restrictions introduced after cutover. Roll forward or reconcile restrictions before a coordinated restoration. No production permission changes should be performed directly in SQL as a substitute for the pending governed administration workflow.
