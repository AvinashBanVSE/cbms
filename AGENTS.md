# CBMS agent entry point

Use this file before exploring the repository.

1. Run `scripts/check-agent-contracts.sh`.
2. If it reports `fresh`, read `docs/agent-contracts.md` and then inspect only the files relevant to the task. Do not inventory or rescan the whole tree.
3. If it reports `stale`, inspect only the changed contract-source files it prints. Reconcile `docs/agent-contracts.md` before relying on the affected statements.
4. Treat cited source code as authoritative. The reference is a navigation aid, never a substitute for authorization, validation, or tests.

## Contract ownership

The agent that changes a contract source owns the reference in the same change:

- If observable contract semantics changed, update the affected statement and its source citation.
- If the edit was semantic-neutral, review the affected statement without rewriting it.
- In either case, refresh the verified source tree using the procedure in `docs/agent-contracts.md` and run the checker again.
- Add a new durable statement only when a fact crosses modules or stacks, is expensive to reconstruct, and being wrong can cause a real defect. Do not add inventories, implementation walkthroughs, or task status.

Keep project documentation in English. Do not change product behavior merely to make it match the reference.
