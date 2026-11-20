import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface LifecycleState {
  state: string;
  version: number;
  allowed_transitions: string[];
}

export function BusinessLifecycle({
  id,
  onChange,
}: {
  id: string;
  onChange: () => void;
}) {
  const [current, setCurrent] = useState<LifecycleState>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState<{ request: string; id: string }>();
  const path = "businesses/" + encodeURIComponent(id) + "/lifecycle";

  async function run(action: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await action();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function load() {
    return run(async () =>
      setCurrent(await (await portalRequest(path)).json()),
    );
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!current) return;
    const data = new FormData(event.currentTarget);
    const request = {
      state: String(data.get("state")),
      expected_version: current.version,
      reason: String(data.get("reason")).trim(),
      ...(current.state === "Pending Verification"
        ? { verification_confirmed: data.has("verification") }
        : {}),
    };
    const signature = JSON.stringify(request);
    const op = retry?.request === signature ? retry.id : crypto.randomUUID();
    setRetry({ request: signature, id: op });
    return run(async () => {
      const result = await (
        await portalRequest(path, { ...request, operation_id: op })
      ).json();
      setRetry(undefined);
      setCurrent(undefined);
      setNotice(
        "New state: " +
          result.state +
          " · Businesses updated: " +
          result.changed_businesses +
          " · Mappings updated: " +
          result.changed_mappings,
      );
      onChange();
    });
  }

  return (
    <details>
      <summary>Manage business state</summary>
      <button disabled={busy} onClick={load}>
        Load business state
      </button>
      {current && (
        <>
          <p>Current state: {current.state}</p>
          {current.allowed_transitions.length > 0 && (
            <form onSubmit={save} data-operation="business-lifecycle">
              <label>
                New business state
                <select name="state" required>
                  {current.allowed_transitions.map((state) => (
                    <option key={state}>{state}</option>
                  ))}
                </select>
              </label>
              <label>
                Reason
                <textarea name="reason" required maxLength={500} />
              </label>
              {current.state === "Pending Verification" && (
                <label>
                  <input name="verification" type="checkbox" required />
                  Business verification completed
                </label>
              )}
              <button disabled={busy}>Update business state</button>
            </form>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
