import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function OrganizationMfaSettings() {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = new FormData(event.currentTarget);
    const tenant = encodeURIComponent(String(fields.get("tenant")).trim());
    setNotice("");
    setBusy(true);
    try {
      const result = await (
        await portalRequest("tenants/" + tenant + "/mfa-policy", {
          required: fields.get("required") === "required",
          reason: String(fields.get("reason")).trim(),
        })
      ).json();
      setNotice(
        result.required
          ? "Authenticator required for this Organization."
          : "Authenticator optional for this Organization.",
      );
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <details>
      <summary>Organization authenticator policy</summary>
      <form data-operation="organization-mfa-policy" onSubmit={save}>
        <label>
          Organization Tenant ID
          <input name="tenant" required />
        </label>
        <label>
          Authenticator policy
          <select name="required" defaultValue="" required>
            <option value="" disabled>
              Select policy
            </option>
            <option value="required">Required</option>
            <option value="optional">Optional</option>
          </select>
        </label>
        <label>
          Reason
          <input name="reason" required maxLength={500} />
        </label>
        <button disabled={busy}>Save authenticator policy</button>
      </form>
      <p role="status">{notice}</p>
    </details>
  );
}
