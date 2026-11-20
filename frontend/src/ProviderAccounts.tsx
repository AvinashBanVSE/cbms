import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function ProviderAccounts() {
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const provider = encodeURIComponent(String(fields.get("provider")).trim());
    const operation = form.dataset.operation;
    const route = "providers/" + provider + "/users";
    const body = Object.fromEntries(
      Array.from(fields.entries())
        .filter(([key]) => key !== "provider")
        .map(([key, value]) => [key, String(value).trim()]),
    );
    setBusy(true);
    setNotice("");
    try {
      const result = await (
        await portalRequest(
          operation === "provider-create"
            ? route
            : route +
                "/" +
                (operation === "provider-reissue" ? "reissue" : "revoke"),
          body,
        )
      ).json();
      const delivery =
        result.delivery === "sent"
          ? "Setup code sent."
          : "Email failed. Reissue the setup code.";
      setNotice(
        result.state === "Terminated"
          ? "Sub-Portal account terminated."
          : result.user_code
            ? "User Code: " + result.user_code + ". " + delivery
            : delivery,
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
      <form data-operation="provider-create" onSubmit={submit}>
        <label>
          Sub-Portal ID
          <input name="provider" required />
        </label>
        <label>
          User email
          <input name="email" type="email" required />
        </label>
        <label>
          <input
            name="permission"
            type="checkbox"
            value="provider.organizations.view"
            required
          />
          View Organizations served by this Sub-Portal
        </label>
        <button disabled={busy}>Create Sub-Portal user</button>
      </form>
      <form data-operation="provider-reissue" onSubmit={submit}>
        <label>
          Sub-Portal ID
          <input name="provider" required />
        </label>
        <label>
          User Code
          <input name="code" required pattern="[A-Z]{2}[0-9]{4}" />
        </label>
        <button disabled={busy}>Reissue Sub-Portal setup code</button>
      </form>
      <form data-operation="provider-revoke" onSubmit={submit}>
        <label>
          Sub-Portal ID
          <input name="provider" required />
        </label>
        <label>
          User Code
          <input name="code" required pattern="[A-Z]{2}[0-9]{4}" />
        </label>
        <label>
          Termination reason
          <input name="reason" required maxLength={500} />
        </label>
        <button disabled={busy}>Terminate Sub-Portal user</button>
      </form>
      <p role="status">{notice}</p>
    </>
  );
}
