# Authorization policy decisions for ratification
Date: 2026-11-24
Status: proposed final rules. The implementation plan and four foundational requirements are approved; these newly specified policy choices await explicit ratification. This is not the final database architecture or migration approval.

## Confirmed requirements
Independent entity-owned roles, a database-backed permission catalogue, explicit assigned scope, and central authorization are required. Permission (action), Role (group of actions), Scope (eligible targets), and Configuration (governed behavior) remain separate. Preserve Master Decisions sections 3.15.1 and 7–11, including one Organization architecture per customer account and Main Portal governing authority.

## D1 — Direct deny and combination
Roles contain positive grants. Authorized administrators may create explicit user-specific denies for identified permissions, business contexts and scopes. Absence of a grant is a default denial; it is not a persisted explicit deny.
Within the same applicable authority context, any matching explicit deny overrides positive grants from all roles, direct grants and delegations. No grant overrides stable-core restrictions, inactive accounts, missing associations, scope exclusions or mandatory policies. A deny outside the target/context has no effect.
An administrator can create/remove a deny only within their restriction-management authority. A subordinate cannot deny Main Portal governing authority, modify an upstream restriction, or remove their own restriction through another role. This jurisdiction rule does not give every Main Portal user unrestricted access.
Removing a deny, widening its exceptions, or allowing a time-limited deny to expire can increase access; show the effective change and apply the access-expansion approval rule. Expiry must be approved when scheduled, with audit at expiry. Default denies have no expiry unless explicitly configured. Role-specific negative grants are excluded from the initial model to keep combination deterministic.

## D2 — Scope selectors, exclusions and future entities
Initial supported selectors: self entity; explicit selected eligible entity IDs; all currently eligible entities as a captured snapshot. All selectors require current business authority, valid account/business association and explicit assignment. Exclusions always subtract.
An additional explicitly authorized selector may include current and future eligible entities. This is a separately governed capability, disabled unless expressly granted to the assigning authority and selected for the assignment. It uses a fixed anchor and supported entity kinds/relationship predicates; never arbitrary SQL, scripts or unconstrained filters.
Dynamic scope means future eligible targets, not future permissions. New operations are never granted by a wildcard. A newly created entity still requires valid association, lifecycle state, and all contextual controls. The explicit association model must define how eligibility is established; dynamic scope cannot manufacture an association.
Evaluate eligibility on each access. Losing an eligible relationship or changing service provider removes invalid access; stored scope entries cannot preserve it. Do not automatically activate dynamic selectors during migration. Do not extend customer accounts to another Organization. Approved owner-linked/provider authority requires its own explicit model and purpose checks.
Adding entities, removing exclusions, switching a snapshot to dynamic, or widening a selector is an access expansion. Narrowing takes effect on subsequent access after committed revocation. Snapshot membership changes require an explicit edit.

## D3 — Approval governance
Approval is evaluated after permission to request and before execution. Business/action rules may vary by role and individual user as required by Master Decisions section 11. Approval cannot confer a missing action grant or scope.
Separate mandatory governing requirements from overridable defaults. Mandatory requirements compose and cannot be weakened locally. For defaults, an authorized user-specific rule takes precedence over applicable role defaults, then business default, then inherited default. Multiple applicable role requirements compose; an exemption in one role cannot cancel another requirement. An explicit user exemption is allowed only where governing policy permits it.
Support single, multiple, sequential and parallel patterns, including one-of-two via a required count of one. Count distinct eligible users, never multiple roles held by the same person. The requester cannot approve their own request. A beneficiary cannot approve their own access expansion. Insufficient eligible reviewers blocks execution and requires policy repair or an existing authorized governing process; never silently auto-approve.
Proposed mandatory protection: expansions affecting authorization administration, permission semantics/applicability, scope-granting/delegation authority, sensitive access or approval-policy weakening require independent approval. Ordinary operational role grants follow the effective configurable business rule. Permission editing on an assigned role is evaluated against every affected assignment.
A committed emergency suspension/revocation by an explicitly authorized actor can immediately restrict access; record reason and audit, with review afterwards where configured. Restoration is an expansion and uses its approval policy. Preserve the last viable portal administrative/recovery path through a dedicated controlled procedure; this protection must not require leaving a compromised account active.
Freeze the requested payload, affected assignments, role/scope and policy versions. Relevant changes invalidate pending approval for resubmission; execution rechecks requester/approver authority, current constraints and versions transactionally. Rejection ends the request; missing reviewers and expiry never imply approval. Approved execution is idempotent.
Keep workflow state separate from the comprehensive audit trail. Do not introduce a separately maintained Approval History product without the distinct approval required by Master Decisions section 11.5.
First-owner installation is a one-time audited bootstrap under the existing deployment-secret state machine. It must not become a reusable approval bypass for normal operation.

## D4 — Template copy consequence
A template creates an entity-owned copy of eligible permission selections only. It copies no users, assignments, scopes, delegations, direct denies or approvals. Record template ID/version. Changes to a template do not update existing roles. An explicit template refresh presents the diff, checks applicability and grant authority, and applies expansion approvals before changing assigned roles.

## Decision examples and required acceptance evidence
- An S1 export deny defeats all S1 export grants, but does not affect S2.
- Adding S3 does not change a snapshot of S1/S2; an approved dynamic assignment still checks S3 association and eligibility.
- Two roles held by one reviewer count as one approval.
- Adding an authorization-management permission to an assigned role is an expansion even if no new assignment row is inserted.
- A copied template does not inherit the source business's users or authority.
- A role or policy change while approval is pending prevents execution under the obsolete request.
- Revocation is enforced in caches and queued work; rollback never restores revoked authority.

## Next gates
After ratification, inspect the existing schema, queries, controllers and middleware to finalize architecture. Prepare migration mappings, parity checks, security scenarios and rollback restrictions for approval before implementation. The known unavailable contract-reference tree remains an entry-gate repair; it is not evidence that these rules are implemented.
