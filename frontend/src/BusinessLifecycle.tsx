import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";
import { indiaTime } from "./indiaTime";

interface LifecycleState {
  state: string;
  version: number;
  allowed_transitions: string[];
  can_configure_closure?: boolean;
}

interface ClosureQueue {
  data: { id: string; reason: string; expires_at: string | null }[];
  next_page_url: string | null;
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
  const [closures, setClosures] = useState<ClosureQueue>();
  const [closurePage, setClosurePage] = useState(1);
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
      if (result.status === "pending") {
        setNotice("Closure awaiting approval: " + result.approval_id);
        return;
      }
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

  async function loadClosures(page: number) {
    const response = await portalRequest(
      path + "/closure-approvals?page=" + page,
    );
    setClosures(await response.json());
    setClosurePage(page);
  }

  function savePolicy(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    return run(async () => {
      await portalRequest(path + "/closure-policy", {
        reviewers: String(data.get("reviewers"))
          .split(",")
          .map((code) => code.trim())
          .filter(Boolean),
        expiry_minutes: Number(data.get("expiry_minutes")) || null,
      });
      setNotice("Closure approval policy saved.");
    });
  }

  function decide(approval: string, decision: "approve" | "reject") {
    return run(async () => {
      const response = await portalRequest(
        path + "/closure-approvals/" + encodeURIComponent(approval),
        { decision },
      );
      const result = await response.json();
      await loadClosures(1);
      setNotice("Closure request " + result.status + ".");
      if (result.status === "approved") {
        setCurrent(undefined);
        onChange();
      }
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
          {current.can_configure_closure && (
            <form
              onSubmit={savePolicy}
              data-operation="business-closure-policy"
            >
              <label>
                Closure reviewer codes
                <input name="reviewers" />
              </label>
              <p>
                Separate codes with commas. One listed reviewer must approve.
                Leave blank to turn off approval for new requests.
              </p>
              <label>
                Approval validity in minutes
                <input
                  name="expiry_minutes"
                  type="number"
                  min="1"
                  max="525600"
                />
              </label>
              <button disabled={busy}>Save closure approval policy</button>
            </form>
          )}
          <button disabled={busy} onClick={() => run(() => loadClosures(1))}>
            Load closure requests
          </button>
          {closures && (
            <>
              {closures.data.length ? (
                <ul aria-label="Closure requests">
                  {closures.data.map((request) => (
                    <li key={request.id}>
                      <p>{request.reason}</p>
                      {request.expires_at && (
                        <p>
                          Expires{" "}
                          {indiaTime.format(new Date(request.expires_at))}
                        </p>
                      )}
                      <button
                        disabled={busy}
                        onClick={() => decide(request.id, "approve")}
                      >
                        Approve closure
                      </button>
                      <button
                        disabled={busy}
                        onClick={() => decide(request.id, "reject")}
                      >
                        Reject closure
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p>No pending closure requests.</p>
              )}
              {closurePage > 1 && (
                <button
                  disabled={busy}
                  onClick={() => run(() => loadClosures(closurePage - 1))}
                >
                  Previous closure requests
                </button>
              )}
              {closures.next_page_url && (
                <button
                  disabled={busy}
                  onClick={() => run(() => loadClosures(closurePage + 1))}
                >
                  Next closure requests
                </button>
              )}
            </>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
