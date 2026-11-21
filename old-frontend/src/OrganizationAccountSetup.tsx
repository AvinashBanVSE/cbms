import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function OrganizationAccountSetup({ onDone }: { onDone: () => void }) {
  const [verified, setVerified] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState("");

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = Object.fromEntries(
      new FormData(event.currentTarget),
    ) as Record<string, string>;
    setBusy(true);
    setNotice("");
    try {
      await portalRequest(
        verified ? "organization-setup/complete" : "organization-setup/verify",
        values,
      );
      if (verified) onDone();
      else setVerified(true);
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <form key={String(verified)} onSubmit={submit}>
        {verified ? (
          <>
            <label>
              New password
              <input
                type="password"
                name="password"
                minLength={15}
                required
                autoComplete="new-password"
              />
            </label>
            <label>
              Confirm password
              <input
                type="password"
                name="password_confirmation"
                minLength={15}
                required
                autoComplete="new-password"
              />
            </label>
          </>
        ) : (
          <>
            <label>
              User Code
              <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
            </label>
            <label>
              Temporary password
              <input
                name="temporary_password"
                type="password"
                autoComplete="off"
              />
            </label>
            <label>
              One-time code
              <input
                name="otp"
                inputMode="numeric"
                pattern="[0-9]{6}"
                autoComplete="one-time-code"
              />
            </label>
          </>
        )}
        <button disabled={busy}>
          {verified ? "Save password" : "Verify setup credentials"}
        </button>
      </form>
      <p role="status">{notice}</p>
    </>
  );
}
