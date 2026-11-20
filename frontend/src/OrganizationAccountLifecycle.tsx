import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface AccountState {
  state: string;
  allowed_transitions: string[];
}

export function OrganizationAccountLifecycle() {
  const [account, setAccount] = useState<{
    path: string;
    code: string;
    status: AccountState;
  }>();
  const [target, setTarget] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function execute(operation: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await operation();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function load(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const fields = new FormData(event.currentTarget);
    const tenant = encodeURIComponent(String(fields.get("tenant")).trim());
    const code = String(fields.get("code")).trim();
    setAccount(undefined);
    setConfirmed(false);
    return execute(async () => {
      const path =
        "tenants/" +
        tenant +
        "/organization-users/" +
        encodeURIComponent(code) +
        "/lifecycle";
      const status = (await (await portalRequest(path)).json()) as AccountState;
      setAccount({ path, code, status });
      setTarget(status.allowed_transitions[0] ?? "");
    });
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!account || !target || (target === "Terminated" && !confirmed)) return;
    const form = event.currentTarget;
    const reason = String(new FormData(form).get("reason")).trim();
    return execute(async () => {
      const status = (await (
        await portalRequest(account.path, {
          state: target,
          expected_state: account.status.state,
          reason,
        })
      ).json()) as AccountState;
      setAccount({ ...account, status });
      setTarget(status.allowed_transitions[0] ?? "");
      setConfirmed(false);
      form.reset();
      setNotice("Account state updated.");
    });
  }

  return (
    <details>
      <summary>Manage account state</summary>
      <form data-operation="account-state-lookup" onSubmit={load}>
        <label>
          Organization Tenant ID
          <input name="tenant" required />
        </label>
        <label>
          User Code
          <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
        </label>
        <button disabled={busy}>Load account state</button>
      </form>
      {account && (
        <>
          <p>
            {account.code} · {account.status.state}
          </p>
          {account.status.allowed_transitions.length > 0 && (
            <form data-operation="account-state-update" onSubmit={save}>
              <label>
                New account state
                <select
                  name="target"
                  value={target}
                  onChange={(event) => {
                    setTarget(event.target.value);
                    setConfirmed(false);
                  }}
                >
                  {account.status.allowed_transitions.map((state) => (
                    <option key={state}>{state}</option>
                  ))}
                </select>
              </label>
              <label>
                Reason
                <textarea name="reason" required maxLength={500} />
              </label>
              {target === "Terminated" && (
                <label>
                  <input
                    type="checkbox"
                    checked={confirmed}
                    onChange={(event) => setConfirmed(event.target.checked)}
                    required
                  />
                  Permanently terminate this account
                </label>
              )}
              <button
                disabled={busy || (target === "Terminated" && !confirmed)}
              >
                Update account state
              </button>
            </form>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
