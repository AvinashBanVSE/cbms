# Entity role administration: backend increment

This implements entity-owned roles using the existing role tables. New roles have `governed=true`, an optimistic `version`, and explicit grant-authority limits separate from use permissions. Existing rows remain `governed=false`; their IDs, permissions, assignments and customer scopes are preserved. Legacy assignment endpoints reject governed roles, preventing approval bypass.

## Endpoints

All routes use the existing authenticated browser session and CSRF controls under `/api/v1/entities/{business}/roles`.

| Method / suffix | Payload / result |
|---|---|
| GET root | Paginated governed roles owned by this entity, including actions and grant_actions |
| POST root | name, actions, grant_actions → id, version |
| PUT /{role} | name, actions, grant_actions, expected_version → id, new version |
| POST /{role}/assign | code, expected_version, optional reviewers (User Codes) → assigned or 202 pending with request id |
| POST /{role}/revoke | code, reason → immediate emergency restriction; restoring access uses assignment approval |
| GET /approvals | Managers see entity pending requests; approval-only users see requests assigned to their current stage |
| POST /approvals/{change}/decision | decision: approve or reject |
| POST /copy | source_business, source_role, expected_version, name → independent role copy |

A user needs `entity_roles.manage` in the entity and current membership/scope to administer its roles. The existing Main Portal `organization_users.manage` grant remains the governing migration authority. `entity_roles.approve` is separately assignable. Granting use permissions is limited by the administrator's explicit grant_actions; holding a permission alone gives no right to distribute it. Only the established Main Portal governing authority can create grant-authority limits in this increment. Assigning those limits requires independent approval.

## Scope and operation boundaries

Roles operate in their owning entity context, using each existing operation's semantics. Customer accounts remain within one Organization; a governed role requires an active account, active entity/Organization, an active explicit business association and an existing customer_business_scopes row. Portal/provider governed roles require their valid account context and an explicit entity_user_scopes record. The additive migration materializes only self scopes already represented by existing portal/provider grants; normal bootstrap/provisioning records the matching explicit self scope. No role assignment creates an association or scope.

This increment does not introduce selected-entity, snapshot, exclusion, owner-linked, or future-entity scope selectors. It does not narrow the established meaning of portal-wide administrative operations or the provider directory. Those resource-specific boundaries and broader scope selectors belong to the central-evaluation cutover; do not represent this increment as fine-grained control of individual records within such operations. Customer entity isolation and existing platform/provider/customer account separations remain enforced.

Role grants are evaluated in SQL with membership, scope, entity state, active catalogue and applicability checks, rather than fetching every entity into a per-entity PHP query loop. Role-based grants feed existing portal, provider, mapping, onboarding, presentation and client-administration checks. No authorization result cache is introduced.

## Version and approval safety

- New role names are unique within their entity. A role from another entity cannot be assigned through the current entity URL.
- Contents of an assigned governed role cannot be edited in this increment. Explicitly revoke assignments, edit with expected_version, then request new assignments. This avoids silently expanding existing users before the later atomic role-change workflow is implemented.
- `grant_actions` and authorization-management operations require independent approval. Other catalogue definitions marked requires_approval also require it. Existing configured business assignment stages are honored; otherwise a mandatory grant requires a separately authorized reviewer selected by the requester. No reviewer means no grant.
- Reviewers cannot be the requester or beneficiary. Distinct users satisfy counts. Sequential stages and parallel multi-user counts are supported; current authority is checked again for earlier votes.
- Requests snapshot role version, permission catalogue/applicability, governing assignment policy, relevant actor/recipient grants/account/scope state and the entity authorization version. Relevant change invalidates execution; no obsolete approved payload is applied. Expiry never grants access. Successful execution is not replayable.
- Requesters and reviewers must still be authorized at execution. Changes and decisions are recorded through the existing portal audit trail. Workflow state is not a separate user-facing Approval History product.
- Copy requires authority to read the source and create in the destination. It copies eligible action selections only, records source role/version, and copies no grant limits, users, assignments, scopes or approvals. Later source changes do not update the copy.

## Migration and remaining boundaries

Apply migration 000053 after 000052. It broadens the role-assignment user foreign key from customer_accounts to users to support platform/provider accounts, while authorization enforces their separate contexts. Existing assignments are unchanged. The schema upgrade has a populated-database preservation test. Automatic down migration is deliberately refused; a reviewed forward migration is required to avoid losing active authority/restrictions.

Direct Deny, scope selectors/exclusions/future entities, the complete policy precedence editor, governed catalogue writes, frontend management and full shadow/cutover remain separate approved increments. No new production defaults for policy durations or counts are established here. Ordinary operations retain the current policy semantics; the non-overridable administration approval floor is enforced server-side.
