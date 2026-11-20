import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

export function CredentialSettings() {
  return (
    <>
      <CredentialPolicy
        endpoint="credential-settings"
        field="password_history_count"
        operation="password-history-policy"
        title="Password history policy"
        label="Previous passwords to block"
        min={5}
        max={10}
      />
      <CredentialPolicy
        endpoint="credential-settings/setup-otp"
        field="validity_minutes"
        operation="setup-otp-policy"
        title="Setup OTP policy"
        label="Setup code validity in minutes"
        min={1}
        max={15}
      />
    </>
  );
}

interface CredentialPolicyProps {
  endpoint: string;
  field: string;
  operation: string;
  title: string;
  label: string;
  min: number;
  max: number;
}

function CredentialPolicy({
  endpoint,
  field,
  operation,
  title,
  label,
  min,
  max,
}: CredentialPolicyProps) {
  const [value, setValue] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const actionLabel = title.charAt(0).toLowerCase() + title.slice(1);

  async function request(save: boolean) {
    setBusy(true);
    setNotice("");
    try {
      const response = await portalRequest(
        endpoint,
        save ? { [field]: Number(value) } : undefined,
      );
      const settings = await response.json();
      setValue(String(settings[field]));
      setNotice(title + (save ? " saved." : " loaded."));
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
      <summary>{title}</summary>
      <button type="button" disabled={busy} onClick={() => request(false)}>
        Load {actionLabel}
      </button>
      <form data-operation={operation} onSubmit={submit}>
        <label>
          {label}
          <input
            name={field}
            type="number"
            min={min}
            max={max}
            required
            value={value}
            onChange={(event) => setValue(event.target.value)}
          />
        </label>
        <button disabled={busy}>Save {actionLabel}</button>
      </form>
      <p role="status">{notice}</p>
    </details>
  );
}
