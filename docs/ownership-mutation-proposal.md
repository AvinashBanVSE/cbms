# Ownership Mutation & Transfer — Gap Analysis and Proposal

Status: **proposal only — not implemented, requires product approval.**
Scope: `backend/app/Domain/BusinessStructure.php`, `BusinessLifecycle.php`, `ServiceProviderMigration.php`, `BusinessStructureController`, `InstallationController`, `Approval/BusinessClosureApproval.php`, tables `ownership_holders` / `ownership_links`.
Evidence: current `main` @ d22fe6f.

## 1. Gap

Ownership is **create-only**. Once recorded, an owner set can never change through the API:

- No `UPDATE`/`DELETE` is ever issued against `ownership_holders` or `ownership_links` (only `INSERT`, in `InstallationController::owner` and `BusinessStructureController::store`).
- No endpoint mutates ownership after creation. `GET /businesses/{organization}/ownership-check` **detects** drift but cannot correct it.

This is a real functional gap, not a theoretical one:

| Trigger for an owner change | Today's outcome |
|---|---|
| Business sold / legal entity transferred | Impossible — no flow |
| Inheritance, death, or retirement of an owner | Impossible |
| Adding a new legal co-owner | Impossible |
| Correcting a recorded-but-wrong owner name | Impossible (`ownership_holders.name` is never updated) |
| Owner set drift from a legacy/corrected record | Flagged by `ownership-check` as `consistent: false`, then stuck |

Because ownership also gates child inheritance (Distributor/Shop must equal the parent owner set) and blocks service-provider migration when inconsistent, an uncorrectable owner record can permanently wedge an Organization.

## 2. Constraints any fix must preserve

These are approved invariants, not incidental behaviour. A mutation flow must not weaken them.

1. **Ownership is a legal registry, not authorization.** A holder is not a user and grants no permission. Mutation must not blur this: never link `ownership_holders` to `users`, never derive grants from ownership.
2. **Child inheritance is exact.** A Distributor/Shop owner set must always equal its parent Organization's set. Mutating an Organization owner set must cascade identically to every direct child, or be rejected.
3. **Child parent is immutable.** Ownership mutation must not become a route to reparent.
4. **Audit is append-only.** Every change recorded in `portal_events` plus an ownership-specific history; prior links never silently disappear.
5. **Idempotent, versioned operations.** Follow the existing `operation_id` + `lifecycle_version` pattern.
6. **Authorization is explicit.** Mutation requires a defined Main Portal permission (candidate: a new `business_structure.ownership` verb), not an ad-hoc check.

## 3. Existing governance pattern to reuse

The codebase already has a two-phase, multi-reviewer, expiring governance mechanism for high-impact, hard-to-reverse business changes: **closure approval** (`Approval/BusinessClosureApproval`, table `business_closure_approvals`, votes in `business_closure_votes`).

Its shape:
- `closurePolicy` — reviewers (authorized users), `required_votes`, `expiry_minutes`; requestor excluded from own decision.
- `request` — creates a pending approval, requires enough authorized reviewers other than the requester.
- `decide` — `approve`/`reject`/`withdraw`; requester cannot approve own; votes tallied to `required_votes`; expiry → `expired`/`stale`.

Ownership transfer is the same class of high-impact change, so the proposal reuses this pattern rather than inventing a new one.

## 4. Proposal

### 4.1 New permission verb
If this proposal is approved, register `business_structure.ownership` through the database-backed permission catalogue and Main Portal applicability (workspace entry `null`, action-only), with a matching implemented operation binding. Configure closure-style policy to name authorized reviewers. Do not reintroduce a PHP permission catalogue.

### 4.2 Two commands, both approval-gated

- **`transfer`** — replace one holder with another across a business and its children (sale, inheritance).
- **`correct`** — fix a holder's `name`, or repair drift where a child owner set diverged from its parent (the `ownership-check` finding).

Add/remove-a-single-owner is expressible as `transfer` with a one-element from/to, so it needs no separate command.

### 4.3 Flow (mirrors closure approval)

```text
POST /businesses/{business}/ownership/transfer
  requester (permission: business_structure.ownership) submits:
    from_owner_id, to_owner_id | new_owner_name, reason
    -> resolves target holder (existing or new), builds command JSON
    -> re-validates: business exists, kind is Organization/Sub-Portal,
       proposed owner set stays valid per BusinessStructure::validateChild,
       every affected child stays equal to parent
    -> creates pending approval (reviewers, required_votes, expiry)
    -> returns { approval_id, status: pending }

GET /businesses/{business}/ownership/requests
  pending requests visible to requester + reviewers (same as pendingClosures)

POST /businesses/{business}/ownership/requests/{approval}/decide
  decision: approve | reject | withdraw
  requester cannot approve own; reviewer must hold the verb
  on quorum approve -> atomically:
    insert ownership history (before/after per business)
    insert new ownership_links, delete superseded links (children in same transaction)
    record portal_events 'business.ownership_changed'
    bump an ownership_version for optimistic concurrency
```

### 4.4 Schema additions (append-only, additive)

```text
ownership_history(
  id, business_id, operation_id, actor_id,
  from_owner_ids json, to_owner_ids json,
  recorded_at, index(business_id, recorded_at)
)
-- reuse a pending-approval table in the closure-approval shape:
ownership_approvals(
  id, business_id, requester_id, command json,
  reviewers json, required_votes int, status, expires_at,
  index(business_id, status, id)
)
ownership_votes(approval_id, reviewer_id)
```

Optional: `ownership_links.operation_id` + an `ownership_version` on `business_identities` for the idempotency/version checks, matching `business_lifecycle_operations`.

### 4.5 Consistency with existing guards

- `ownership-check` gains a "propose correction" affordance that files a `correct` request pre-filled from the drift.
- `ServiceProviderMigration::preview` is unchanged — migration still blocks while inconsistent; an approved correction is what unblocks it.
- Lifecycle snapshots already persist `owner_ids`; restore is unaffected because mutation only changes ownership, never `operational_state`.

## 5. Out of scope / open product questions

1. Should an Organization owner change be allowed while the Organization is not `Active`? (Proposal: allow only `Active`, mirroring lifecycle preconditions.)
2. Should Main Portal ownership (from installation) be transferable at all, or is that a deployment-owner action outside the product? (Proposal: exclude Main Portal; treat as install-scoped.)
3. Is a single authorized approver enough for low-risk `correct`, with multi-vote reserved for `transfer`?
4. Does a holder need a typed identity (PAN/CIN/GSTIN) before mutation, to make "same owner?" unambiguous? (Related gap: `ownership_holders` has only `name`, so duplicate names are indistinguishable.)

## 6. Acceptance criteria

- Every mutation path records before/after owner sets in `ownership_history` and `portal_events`.
- An approved transfer leaves every affected Distributor/Shop owner set equal to its parent; `ownership-check` returns `consistent: true`.
- Requester cannot approve their own transfer; quorum and expiry enforced.
- Unauthorized callers receive 403; no ownership write is reachable without the verb.
- Idempotent retry with the same `operation_id` returns the recorded result, never double-applies.
- Feature tests cover transfer (happy + unauthorized + child cascade), correct-from-drift, and expiry/stale.
