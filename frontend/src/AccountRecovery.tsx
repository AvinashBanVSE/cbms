import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function AccountRecovery({
  tenantId,
  provider = false,
}: {
  tenantId?: string;
  provider?: boolean;
}) {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const scope = encodeURIComponent(
      tenantId ?? String(fields.get("scope")).trim(),
    );
    const path = provider
      ? "providers/" + scope + "/users/recover"
      : "tenants/" + scope + "/organization-users/recover";
    setBusy(true);
    setNotice("");
    try {
      const result = await (
        await portalRequest(path, {
          code: String(fields.get("code")).trim(),
          reason: String(fields.get("reason")).trim(),
        })
      ).json();
      setNotice(
        result.delivery === "sent"
          ? "Temporary password sent to the registered email."
          : "Email delivery failed. Issue a new temporary password when the retry window opens.",
      );
      if (result.delivery === "sent") form.reset();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <details>
      <summary>Recover an active account</summary>
      <form data-operation="account-recovery" onSubmit={submit}>
        {!tenantId && (
          <label>
            {provider ? "Sub-Portal ID" : "Organization Tenant ID"}
            <input name="scope" required />
          </label>
        )}
        <label>
          User Code
          <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
        </label>
        <label>
          Recovery reason
          <input name="reason" maxLength={500} required />
        </label>
        <p>
          Issuing a temporary password ends existing sessions. The user sets a
          new password through Set up account.
        </p>
        <button disabled={busy}>Send temporary password</button>
      </form>
      <p role="status">{notice}</p>
    </details>
  );
}
