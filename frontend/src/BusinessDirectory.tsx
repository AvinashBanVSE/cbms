import { useEffect, useState, type FormEvent } from "react";
import { BusinessLifecycle } from "./BusinessLifecycle";
import { ProviderMigration } from "./ProviderMigration";
import { portalRequest } from "./api";

interface Business {
  id: string;
  kind: string;
  professional_name: string;
  legal_name: string | null;
  legal_identity_id?: string | null;
  operational_state?: string;
  parent_id: string | null;
  provider_id: string | null;
  tenant_id: string | null;
  owners: { owner_id: string; name: string }[];
}

export function BusinessDirectory() {
  const [kind, setKind] = useState("Organization");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [directory, setDirectory] = useState<{
    data: Business[];
    next_page_url: string | null;
  }>();
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  const child = kind === "Distributor" || kind === "Shop";

  useEffect(() => {
    let current = true;
    portalRequest("businesses?page=" + page)
      .then((response) => response.json())
      .then((result) => {
        if (current) setDirectory(result);
      })
      .catch((error: Error) => {
        if (current) setMessage(error.message);
      });
    return () => {
      current = false;
    };
  }, [page, revision]);

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const payload: Record<string, string | string[]> = { kind };
    for (const field of [
      "professional_name",
      "legal_name",
      "parent_id",
      "provider_id",
      "new_owner_name",
    ]) {
      const value = String(data.get(field) ?? "").trim();
      if (value) payload[field] = value;
    }
    const owners = String(data.get("owner_ids") ?? "")
      .split(/[\s,]+/)
      .filter(Boolean);
    if (owners.length) payload.owner_ids = owners;
    setSaving(true);
    setMessage("");
    try {
      await portalRequest("businesses", payload);
      form.reset();
      setMessage("Business created.");
      setRevision((value) => value + 1);
    } catch (error) {
      setMessage((error as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function checkOwnership(id: string) {
    setMessage("");
    try {
      const result = await (
        await portalRequest(
          "businesses/" + encodeURIComponent(id) + "/ownership-check",
        )
      ).json();
      setMessage(
        result.consistent
          ? "Organization ownership links are consistent."
          : "Review ownership links for: " + result.business_ids.join(", "),
      );
    } catch (error) {
      setMessage((error as Error).message);
    }
  }

  return (
    <>
      <p role="status">{message}</p>
      <label>
        Business type
        <select value={kind} onChange={(event) => setKind(event.target.value)}>
          {["Organization", "Sub-Portal", "Distributor", "Shop"].map((type) => (
            <option key={type}>{type}</option>
          ))}
        </select>
      </label>
      <form key={kind} onSubmit={create}>
        <label>
          Professional name
          <input name="professional_name" maxLength={200} required />
        </label>
        {!child && (
          <label>
            Legal name
            <input name="legal_name" maxLength={200} required />
          </label>
        )}
        {kind === "Organization" && (
          <label>
            Service provider ID
            <input name="provider_id" required />
          </label>
        )}
        {child ? (
          <label>
            Parent Organization ID
            <input name="parent_id" required />
          </label>
        ) : (
          <>
            <label>
              Existing owner IDs
              <input name="owner_ids" aria-describedby="owner-format" />
            </label>
            <p id="owner-format">
              Separate multiple IDs with commas. Find owner IDs in the directory
              below.
            </p>
            <label>
              New owner name
              <input name="new_owner_name" maxLength={200} />
            </label>
          </>
        )}
        <button disabled={saving}>Create business</button>
      </form>
      <ProviderMigration onComplete={() => setRevision((value) => value + 1)} />
      {directory && (
        <>
          <h2>Registered businesses</h2>
          {directory.data.map((business) => (
            <article key={business.id}>
              <h3>{business.professional_name}</h3>
              {business.kind === "Organization" && (
                <button onClick={() => checkOwnership(business.id)}>
                  Check ownership links
                </button>
              )}
              <dl>
                <dt>Type</dt>
                <dd>{business.kind}</dd>
                <dt>Business ID</dt>
                <dd>{business.id}</dd>
                {business.legal_name && (
                  <>
                    <dt>Legal name</dt>
                    <dd>{business.legal_name}</dd>
                  </>
                )}
                {business.legal_identity_id && (
                  <>
                    <dt>Verified Legal Identity ID</dt>
                    <dd>{business.legal_identity_id}</dd>
                  </>
                )}
                {business.provider_id && (
                  <>
                    <dt>Service provider ID</dt>
                    <dd>{business.provider_id}</dd>
                  </>
                )}
                {business.tenant_id && (
                  <>
                    <dt>Tenant ID</dt>
                    <dd>{business.tenant_id}</dd>
                  </>
                )}
                {business.parent_id && (
                  <>
                    <dt>Parent Organization ID</dt>
                    <dd>{business.parent_id}</dd>
                  </>
                )}
                <dt>Owners</dt>
                <dd>
                  {business.owners.map((owner) => (
                    <div key={owner.owner_id}>
                      {owner.name} ({owner.owner_id})
                    </div>
                  ))}
                </dd>
                {business.operational_state && (
                  <>
                    <dt>Operational state</dt>
                    <dd>{business.operational_state}</dd>
                  </>
                )}
              </dl>
              {["Organization", "Distributor", "Shop"].includes(
                business.kind,
              ) && (
                <BusinessLifecycle
                  id={business.id}
                  onChange={() => setRevision((value) => value + 1)}
                />
              )}
            </article>
          ))}
          {page > 1 && (
            <button onClick={() => setPage(page - 1)}>Previous</button>
          )}
          {directory.next_page_url && (
            <button onClick={() => setPage(page + 1)}>Next</button>
          )}
        </>
      )}
    </>
  );
}
