import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export interface ActiveAccessDelegation {
  id: string;
  tenant_id: string;
  business_id: string;
  ends_at: string;
  professional_name: string;
}

export function OrganizationDelegations() {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const value = (name: string) => String(fields.get(name)).trim();
    const revoke = form.dataset.operation === "delegation-revoke";
    setBusy(true);
    setNotice("");
    try {
      const result = await (
        await (revoke
          ? portalRequest(
              "organization-access-delegations/" +
                encodeURIComponent(value("grant")) +
                "/revoke",
              {},
            )
          : portalRequest(
              "tenants/" +
                encodeURIComponent(value("tenant")) +
                "/businesses/" +
                encodeURIComponent(value("business")) +
                "/access-delegations",
              {
                code: value("code"),
                minutes: value("minutes"),
                actions: fields.getAll("actions").map(String),
              },
            ))
      ).json();
      setNotice(revoke ? "Delegation revoked." : "Delegation ID: " + result.id);
      form.reset();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <details>
      <summary>Delegate business access management</summary>
      <p role="status">{notice}</p>
      <form onSubmit={submit} data-operation="delegation-create">
        <label>
          Organization Tenant ID
          <input name="tenant" required />
        </label>
        <label>
          Shop or Distributor ID
          <input name="business" required />
        </label>
        <label>
          Recipient User Code
          <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
        </label>
        <label>
          Duration in minutes
          <input name="minutes" type="number" min="1" max="43200" required />
        </label>
        <fieldset>
          <legend>Mapping actions the recipient may administer</legend>
          {[
            ["mapping.initiate", "Generate Shop code"],
            ["mapping.confirm", "Confirm Distributor code"],
            ["mapping.verify", "Verify Shop code"],
            ["mapping.end", "End mapping"],
            ["mapping.history.view", "View Mapping History"],
          ].map(([value, label]) => (
            <label key={value}>
              <input name="actions" type="checkbox" value={value} />
              {label}
            </label>
          ))}
        </fieldset>
        <button disabled={busy}>Grant delegation</button>
      </form>
      <form onSubmit={submit} data-operation="delegation-revoke">
        <label>
          Delegation ID
          <input name="grant" required />
        </label>
        <button disabled={busy}>Revoke delegation</button>
      </form>
    </details>
  );
}
