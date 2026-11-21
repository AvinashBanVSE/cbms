import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function LegalIdentities() {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [external, setExternal] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const attaching = form.dataset.operation === "attach-legal-identity";
    const path = attaching
      ? "businesses/" +
        encodeURIComponent(String(fields.get("business_id")).trim()) +
        "/legal-identity"
      : external
        ? "legal-identities/verify-externally"
        : "legal-identities";
    const body: Record<string, string | boolean> = attaching
      ? { legal_identity_id: String(fields.get("legal_identity_id")).trim() }
      : {
          legal_name: String(fields.get("legal_name")).trim(),
          evidence_reference: String(fields.get("evidence_reference")).trim(),
          ...(external
            ? {}
            : { evidence_reviewed: fields.has("evidence_reviewed") }),
        };
    setBusy(true);
    setNotice("");
    try {
      const result = await (await portalRequest(path, body)).json();
      setNotice(
        attaching
          ? "Legal identity associated."
          : "Verified Legal Identity ID: " + result.id,
      );
      form.reset();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <form data-operation="record-legal-identity" onSubmit={submit}>
        <label>
          Legal name
          <input name="legal_name" required maxLength={200} />
        </label>
        <label>
          Evidence reference
          <input name="evidence_reference" required maxLength={200} />
        </label>
        <label>
          Verification method
          <select
            value={external ? "external" : "manual"}
            onChange={(event) => setExternal(event.target.value === "external")}
          >
            <option value="manual">Record evidence review</option>
            <option value="external">Verify with approved registry</option>
          </select>
        </label>
        {!external && (
          <label>
            <input name="evidence_reviewed" type="checkbox" required />I
            reviewed the legal identity evidence
          </label>
        )}
        <button disabled={busy}>Record legal identity verification</button>
      </form>
      <form data-operation="attach-legal-identity" onSubmit={submit}>
        <label>
          Business ID
          <input name="business_id" required />
        </label>
        <label>
          Verified Legal Identity ID
          <input name="legal_identity_id" required />
        </label>
        <button disabled={busy}>Associate legal identity</button>
      </form>
      <p role="status">{notice}</p>
    </>
  );
}
