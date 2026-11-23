# Entity-scoped authorization and configuration development plan

Status: plan approved; policy decisions ratified and implementation authorized on 2026-11-24. See authorization-policy-decisions.md for the ratified rules and authorization-implementation-status.md for delivery progress. This plan is not itself proof of implemented behavior. Prepared 2026-11-23 from main d22fe6f on audit/hardcoded-policy-20261123.

## 1. Authority and observed gaps

Follow Master Business & Architecture Decisions sections 3.15.1, 7–11 and Approved Core Rules sections 7 and 10. The user's clarification requires database-managed permission definitions and independently managed roles for Main Portal, Sub-Portal, Organization, Distributor and Shop. This supersedes any interpretation of the earlier hardcoded-values review that retains PHP arrays as the authoritative permission catalogue.

Actual code observations:
- PermissionCatalogue::MAIN_PORTAL and MAPPING_BY_BUSINESS are authoritative PHP arrays; MappingPermissions::MAX_ACTIONS duplicates the catalogue size.
- OrganizationBusinessRoles and OrganizationRoleController already persist business roles, permissions and assignments, but businessKind accepts only direct Shop/Distributor children. Role management requires MainPortalAccess with organization_users.manage. This is not yet entity self-administration.
- MainPortalAccess reads portal_grants and ProviderAccess reads provider_account_permissions. These separate enforcement paths must retain their account and scope restrictions during migration.
- OrganizationCredentialPolicy already demonstrates stored policy precedence. It should inform, not automatically dictate, inheritance for every setting.
- The contract checker fails because its verified tree is unavailable; some cited frontend files are absent. Repair and reconcile this independently before treating the reference as verified.

## 2. Fixed boundaries, configurable choices, extension points

| Fixed and server-enforced | Database-managed | Requires implementation when genuinely new |
|---|---|---|
| Entity types and legal structural relationships; account tenant boundary; ownership/data isolation | Permission catalogue metadata, applicability, assignability and delegation eligibility | New resource operations and their validation, authorization bindings and tests |
| No implicit grants from hierarchy, ownership, service-provider relationship or mapping | Entity roles, role contents, direct user rules, explicit scope assignments | New policy attributes/operators or authentication mechanisms |
| Valid association AND assigned scope AND action authorization | Governed defaults, entity overrides and override permissions | New settings with new business effects |
| Non-overridable integrity bounds and enforcement on every entry point | Supported approval rules, reviewers and stages | New workflow executors or supported client capabilities |

A database permission record represents a supported operation. Adding a row must never manufacture an API, execute arbitrary code, or bypass an implementation binding. Existing capabilities can be grouped, assigned, restricted and configured without a source edit. New functional behavior still needs code. No role name such as Admin, Owner or Manager is an authorization shortcut.

## 3. Separate functional domains from authority scope

Functional domains classify what an operation concerns: identity/accounts, business structure/lifecycle, authorization/roles, mapping, configuration, service catalogue, subscriptions/entitlements, client activation and audit. These are proposed catalogue groupings of existing foundation capabilities, not new modules.

Authority scope identifies the business entities and records on which a user may act. A website domain such as cbmsys.in is a routing/branding concern and never establishes authorization.

Permission definition = stable code + functional domain + resource + action + applicable entity kinds/contexts + supported scope types + sensitivity applicability + assignable/delegable flags + lifecycle status/version. One central definition can apply to both Shop and Distributor; the grants remain separate. Normal-category grants must not authorize sensitive resources; sensitive applicability follows the approved category policy. UI grouping does not determine access.

## 4. Independent entity roles and explicit assignments

Every role belongs to exactly one Business Entity. The same name may exist in several entities, with unrelated contents. Global templates are optional starting points copied explicitly; changing a template must not silently change existing entity roles.

Role assignment binds an eligible user association, role-owning entity, explicit authority context, assigned target scope, validity and status. The target must fall within both the assigning business's available authority and the user's assigned authority. Cross-entity use requires an explicit approved scope rule; a role must never travel with a user automatically. New entities are excluded from existing scopes unless a specific authorized future-entity rule says otherwise.

A customer account continues to belong to exactly one Organization architecture. The same human acting for a different Organization must use the applicable separate eligible account; do not introduce cross-tenant account membership as part of this refactor. Within one Organization, a user can have a local role for Shop S1 and a different role for S2.

Main Portal has ecosystem-wide business authority under section 7.2; individual portal users still need explicit association, scope and action grants. Subordinate configuration cannot veto Main Portal's governing authority. Provider users gain no unrestricted customer access from serving an Organization. Organization, Distributor and Shop role administrators manage only the entities and permissions they are explicitly authorized to administer.

Separate role creation, role permission editing, assignment/revocation, scope administration, policy publication and permission-catalogue administration. A role editor may grant only permissions they are authorized to grant, not merely permissions they can use. Permission-catalogue semantic administration belongs to explicitly authorized Main Portal staff; local admins manage eligible role contents. Names, descriptions and grouping may be editable without altering operation identity. Published codes and operation bindings cannot be casually renamed or repointed.

## 5. Logical persistence model

These are conceptual records; map them onto existing tables before deciding which migrations are necessary.

| Record group | Required ownership/integrity |
|---|---|
| Domains, resources, actions, permission definitions and applicability | Central catalogue; unique stable codes; explicit executable operation binding; version/status |
| Roles and role-permission links | role.owner_business_id; unique role name within owner; permission applicability validated |
| Role assignments and direct user rules | Valid user association; grantor; entity/context; scope reference; effective/expiry time; status/version |
| User scope assignments, targets and exclusions | Explicit grantor authority; immutable tenant boundary; no implicit future targets |
| Delegations | Who, what, where, limits, duration and revocation; downward and non-escalating |
| Policy definitions/versions and entity configuration | Typed value schema, allowed range, applicable scopes, governing parent, override permission/lock |
| Approval requests and audit history | Separate execution approval; snapshots of requested change and governing versions; append-only history |

Use UUIDv7 for new necessary identifiers. Add foreign keys, scoped uniqueness and composite references where applicable to prevent cross-entity joins. Do not use tenant_id alone as entity isolation: two Shops share a tenant but require independent grants. Query predicates must include target business and current authorization context. Historical permissions are retired rather than deleted while referenced.

## 6. Central decision and enforcement

Resolve trusted account and active business context; load resource ownership server-side. Evaluate stable core, account/association state, business available scope, explicit user scope, applicable active permission grants, and contextual restrictions. Missing or unknown inputs deny. Preserve independent entitlement checks and lifecycle preconditions. Authorization may permit submitting an approval request; execution remains blocked until required approval is complete and authorization is rechecked.

RBAC supplies baseline grants; supported ABAC conditions narrow them; hierarchy/relationship scope is independent. A central policy evaluator coordinates them. It must return a decision with a reason and versions used, not just a role name. Query scoping must enforce list/count/search/export isolation before records are returned. Enforce the same rules on REST, background jobs and all supported clients. Menu visibility is only a presentation of effective access.

Proposed combination rule: union eligible positive role/direct grants inside the current context, then intersect with mandatory bounds and scope; explicit exclusions and applicable prohibitions win. Subordinate prohibitions apply within their permitted jurisdiction, not against Main Portal's governing authority. Adopt this rule explicitly before implementing direct-deny semantics.

Recheck actor authority transactionally for role/scope changes. When an assigned role gains permissions, evaluate affected assignees and approval requirements; permission expansion is an effective grant change. Pending approvals snapshot role/policy versions; edits or revocations trigger revalidation. Delegated authority is revalidated against the issuer's current authority. Prevent deletion/revocation of the final viable authorized portal administrator without a supported transfer/recovery path.

## 7. Configuration and customization

Each setting declares type, scope, default source, governing relationship, bounds, whether overrides are supported, and effects on existing records. Resolve the applicable Main Portal → Sub-Portal (where applicable) → Organization → local entity chain only for settings governed by those relationships. Service-provider relationships must not be confused with structural parentage. An explicit local override requires both business-level override eligibility and user permission to edit it. A sibling remains unaffected.

Expose effective value, source, local override, locked status and reason. Version edits and require expected_version to prevent lost updates. Define how a provider migration changes effective settings; do not silently reinterpret issued credentials or historical subscription terms. Cosmetic branding/navigation options can be customized within supported bounds; they must never create capabilities or grants.

## 8. Scalability and cache correctness

Index by entity, user association, role, permission, status and scope; batch-load decisions for page lists. Cache keys include principal/account context, owning and target entity, role/scope/policy versions and relevant entitlement/state versions. Revocation must invalidate subsequent requests and queued execution; use transactional version bumps with reliable invalidation. Sensitive mutations recheck authoritative current state. Avoid global user-only permission caches and unbounded recursive hierarchy traversal. Record decision reasons without exposing secrets or sensitive resource content. Benchmark actual entity/role volumes before setting performance targets.

## 9. Delivery sequence and acceptance gates

1. Baseline: repair contract-reference verification; inventory existing protected operations and grants, document current allow/deny behavior, and agree ambiguity resolutions below. Snapshot grants and record migration rollback boundaries.
2. Catalogue: add DB records and validated administrative APIs; seed exact existing codes and applicability idempotently. No automatic new grants. Register code operation bindings and reject unsupported activation. Replace numeric action caps with catalogue-derived validation.
3. Entity roles: evolve existing role tables and add missing entity contexts; scoped CRUD and assignment APIs with grant ceilings, direct rules and explicit scope. Preserve installation bootstrap authority and existing account boundaries.
4. Central evaluator: adapt existing MainPortalAccess, ProviderAccess and customer paths. Run new decisions in shadow comparison while old enforcement remains authoritative; investigate every allow/deny difference. Never combine old and new results with OR.
5. Configuration and frontend: catalogue-driven role editor, eligible permission lists, scope picker, effective-access explanation, override/source display and audit view. Backend validates independently of disabled UI controls. Integrate with actual current routes, not absent reference paths.
6. Controlled cutover: reconcile every migrated assignment, compare scope and capability parity, enable one bounded context at a time, then remove PHP catalogue authority and redundant authorization paths. Maintain a single authoritative writer. Rollback must preserve revocations and new constraints; if legacy logic cannot represent them, fail closed or pause affected operations rather than restoring broader access.
7. Cross-impact verification: installation, sign-in/session invalidation, credential recovery, lifecycle suspension, provider migration, mapping, approvals, entitlements, scheduled jobs, audit and navigation. Refresh source-linked contracts in the same implementation changes.

Acceptance tests must prove: same role name has independent contents across entities; Shop S1 grants do not authorize S2; roles cannot bypass missing association or scope; customer accounts cannot cross Organizations; Main Portal scope does not imply every portal user's access; provider relationships and mapping do not grant customer data; new entities/permissions are not silently granted; unauthorized role edits and grant amplification fail; sensitive data is excluded from Normal grants; revocation affects cache/jobs and pending approvals; concurrent edits are detected; imported grants preserve prior restrictions; unknown operation/configuration inputs deny. Test API access directly, not only menu visibility.

## 10. Decisions to settle before implementation

The user's requested DB-backed catalogue and independent entity roles are requirements. Proposed table layout, explicit-deny combination, template copy behavior, final-administrator protection and migration mechanism are implementation recommendations, not already approved master decisions. Resolve direct-deny semantics, supported scope selectors/future-entity rules, and which role/policy changes require approval before schema and UI freeze. Preserve current safe defaults until decisions are recorded. Do not invent numerical configuration defaults in this plan.
