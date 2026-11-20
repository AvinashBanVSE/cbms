import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

const actions = [
  ["mapping.initiate", "Generate Shop code"],
  ["mapping.confirm", "Confirm Distributor code"],
  ["mapping.verify", "Verify Shop code"],
  ["mapping.end", "End mapping"],
];

export function OrganizationUsers() {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const operation = form.dataset.operation;
    const fields = new FormData(form);
    const tenant = encodeURIComponent(String(fields.get("tenant")).trim());
    const base = "tenants/" + tenant;
    const policy = operation === "policy";
    const reissue = operation === "reissue";
    setBusy(true);
    setNotice("");
    try {
      const endpoint = policy
        ? "/credential-policy"
        : reissue
          ? "/organization-users/reissue"
          : "/organization-users";
      const body: Record<string, string | string[]> = policy
        ? { mode: String(fields.get("mode")) }
        : reissue
          ? { code: String(fields.get("code")) }
          : {
              email: String(fields.get("email")),
              business_id: String(fields.get("business_id")),
              actions: fields.getAll("actions").map(String),
            };
      const result = await (await portalRequest(base + endpoint, body)).json();
      if (policy) {
        setNotice("Credential setup policy saved.");
      } else {
        const delivery =
          result.delivery === "sent"
            ? "Setup credentials delivered."
            : "Email could not be sent. Reissue the setup credentials.";
        setNotice(
          result.user_code
            ? "User Code: " + result.user_code + ". " + delivery
            : delivery,
        );
      }
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <p role="status">{notice}</p>
      <details>
        <summary>Credential setup policy</summary>
        <form data-operation="policy" onSubmit={submit}>
          <label>
            Organization Tenant ID
            <input name="tenant" required />
          </label>
          <label>
            Setup method
            <select name="mode" required>
              <option value="temporary_password">
                Email temporary password
              </option>
              <option value="otp">Email one-time code</option>
              <option value="both">Both required</option>
            </select>
          </label>
          <button disabled={busy}>Save policy</button>
        </form>
      </details>
      <details>
        <summary>Create authorized user</summary>
        <form onSubmit={submit}>
          <label>
            Organization Tenant ID
            <input name="tenant" required />
          </label>
          <label>
            User email
            <input name="email" type="email" required />
          </label>
          <label>
            Shop or Distributor ID
            <input name="business_id" required />
          </label>
          <fieldset>
            <legend>Allowed mapping actions</legend>
            {actions.map(([value, label]) => (
              <label key={value}>
                <input name="actions" type="checkbox" value={value} />
                {label}
              </label>
            ))}
          </fieldset>
          <button disabled={busy}>Create user</button>
        </form>
      </details>
      <details>
        <summary>Reissue setup credentials</summary>
        <form data-operation="reissue" onSubmit={submit}>
          <label>
            Organization Tenant ID
            <input name="tenant" required />
          </label>
          <label>
            User Code
            <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
          </label>
          <button disabled={busy}>Reissue credentials</button>
        </form>
      </details>
    </>
  );
}
