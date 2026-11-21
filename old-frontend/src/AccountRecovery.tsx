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
  const [replaceAuthenticator, setReplaceAuthenticator] = useState(false);

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
          ...(!tenantId && fields.has("replace_authenticator")
            ? {
                replace_authenticator: true,
                identity_verified: fields.has("identity_verified"),
              }
            : {}),
        })
      ).json();
      setNotice(
        result.delivery === "sent"
          ? "Temporary password sent to the registered email."
          : "Email delivery failed. Issue a new temporary password when the retry window opens.",
      );
      if (result.delivery === "sent") {
        form.reset();
        setReplaceAuthenticator(false);
      }
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
        {!tenantId && (
          <label>
            <input
              type="checkbox"
              name="replace_authenticator"
              checked={replaceAuthenticator}
              onChange={(event) =>
                setReplaceAuthenticator(event.target.checked)
              }
            />
            Replace a lost or compromised authenticator
          </label>
        )}
        {replaceAuthenticator && (
          <label>
            <input type="checkbox" name="identity_verified" required />I
            verified the user’s identity. Their current authenticator will be
            revoked and they must enroll a new one before signing in.
          </label>
        )}
        <button disabled={busy}>Send temporary password</button>
      </form>
      <p role="status">{notice}</p>
    </details>
  );
}
