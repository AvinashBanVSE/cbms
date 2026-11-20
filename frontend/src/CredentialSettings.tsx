import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function CredentialSettings() {
  const [depth, setDepth] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function request(save: boolean) {
    setBusy(true);
    setNotice("");
    try {
      const response = await portalRequest(
        "credential-settings",
        save ? { password_history_count: Number(depth) } : undefined,
      );
      const settings = await response.json();
      setDepth(String(settings.password_history_count));
      setNotice(
        save
          ? "Password history policy saved."
          : "Password history policy loaded.",
      );
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    return request(true);
  }

  return (
    <details>
      <summary>Password history policy</summary>
      <button type="button" disabled={busy} onClick={() => request(false)}>
        Load password history policy
      </button>
      <form data-operation="password-history-policy" onSubmit={submit}>
        <label>
          Previous passwords to block
          <input
            name="password_history_count"
            type="number"
            min="5"
            max="10"
            required
            value={depth}
            onChange={(event) => setDepth(event.target.value)}
          />
        </label>
        <button disabled={busy}>Save password history policy</button>
      </form>
      <p role="status">{notice}</p>
    </details>
  );
}
