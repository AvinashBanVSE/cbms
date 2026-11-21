import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface MigrationScope {
  source_id: string;
  destination_id: string;
  tenant_id?: string;
}

interface Preview {
  fingerprint: string;
  tenants: { tenant_id: string; name: string; businesses: { id: string }[] }[];
  blocked: Record<string, string[]>;
  can_execute: boolean;
}

export function ProviderMigration({ onComplete }: { onComplete: () => void }) {
  const [review, setReview] = useState<{
    input: MigrationScope;
    plan: Preview;
    operation: string;
  }>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function run(task: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await task();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function preview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const tenant = String(data.get("tenant_id")).trim();
    const input = {
      source_id: String(data.get("source_id")).trim(),
      destination_id: String(data.get("destination_id")).trim(),
      ...(tenant ? { tenant_id: tenant } : {}),
    };
    setReview(undefined);
    return run(async () => {
      const plan = (await (
        await portalRequest("provider-migrations/preview", input)
      ).json()) as Preview;
      setReview({ input, plan, operation: crypto.randomUUID() });
    });
  }

  function migrate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!review?.plan.can_execute) return;
    const reason = String(
      new FormData(event.currentTarget).get("reason"),
    ).trim();
    return run(async () => {
      const result = await (
        await portalRequest("provider-migrations/execute", {
          ...review.input,
          operation_id: review.operation,
          fingerprint: review.plan.fingerprint,
          reason,
        })
      ).json();
      setNotice(
        "Migration completed. Tenants moved: " + result.tenants_migrated,
      );
      setReview(undefined);
      onComplete();
    });
  }

  return (
    <details>
      <summary>Service provider migration</summary>
      <form data-operation="provider-migration-preview" onSubmit={preview}>
        <label>
          Source Sub-Portal ID
          <input
            name="source_id"
            required
            onChange={() => setReview(undefined)}
          />
        </label>
        <label>
          Destination provider ID
          <input
            name="destination_id"
            required
            onChange={() => setReview(undefined)}
          />
        </label>
        <label>
          Organization Tenant ID, or leave blank for the entire Sub-Portal
          <input name="tenant_id" onChange={() => setReview(undefined)} />
        </label>
        <button disabled={busy}>Preview migration</button>
      </form>
      {review && (
        <>
          <h3>Organizations in this migration</h3>
          {review.plan.tenants.length === 0 && (
            <p>No eligible Organizations were found.</p>
          )}
          <ul>
            {review.plan.tenants.map((item) => (
              <li key={item.tenant_id}>
                {item.name} · {item.businesses.length} businesses
                {review.plan.blocked[item.tenant_id]?.map((issue) => (
                  <p key={issue}>{issue}</p>
                ))}
              </li>
            ))}
          </ul>
          {review.plan.can_execute && (
            <form
              data-operation="provider-migration-execute"
              onSubmit={migrate}
            >
              <label>
                Reason
                <textarea name="reason" required maxLength={500} />
              </label>
              <button disabled={busy}>
                Confirm service provider migration
              </button>
            </form>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
