import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface Approval {
  id: string;
  assign: boolean;
  stage: number;
  role: string;
  code: string;
}

export function RoleApprovals({ base }: { base: string }) {
  const [notice, setNotice] = useState("");
  const [items, setItems] = useState<Approval[]>([]);
  const [page, setPage] = useState(1);
  const [next, setNext] = useState(false);
  const [busy, setBusy] = useState(false);

  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setNotice("");
    try {
      await fn();
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function load(number: number) {
    const result = await (await portalRequest(base + "?page=" + number)).json();
    setItems(result.data);
    setPage(number);
    setNext(Boolean(result.next_page_url));
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    return run(async () => {
      const stages = String(data.get("stages"))
        .split("\n")
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
          const [count, reviewers] = line.split(":");
          if (!reviewers || !/^[1-9][0-9]*$/.test(count.trim())) {
            throw new Error(
              "Enter the required count and reviewer codes for each stage.",
            );
          }
          return {
            required: Number(count),
            reviewers: reviewers.split(",").map((code) => code.trim()),
          };
        });
      const result = await (
        await portalRequest(base + "/policy", {
          operation: String(data.get("operation")),
          stages,
          ...(data.has("cancel_pending") ? { cancel_pending: true } : {}),
        })
      ).json();
      if (result.cancelled_requests !== undefined) {
        await load(1);
        setNotice(
          `Approval policy saved. Stages: ${result.stage_count}. Pending requests cancelled: ${result.cancelled_requests}.`,
        );
      } else {
        setNotice("Approval policy saved. Stages: " + result.stage_count);
      }
    });
  }

  function decide(id: string, decision: "approve" | "reject") {
    return run(async () => {
      const result = await (
        await portalRequest(base + "/" + encodeURIComponent(id) + "/decision", {
          decision,
        })
      ).json();
      await load(page);
      setNotice("Request status: " + result.status);
    });
  }

  return (
    <details>
      <summary>Role assignment approvals</summary>
      <form data-operation="role-approval-policy" onSubmit={save}>
        <label>
          Role operation
          <select name="operation">
            <option value="assign">Assign role</option>
            <option value="revoke">Remove role</option>
          </select>
        </label>
        <label>
          Approval stages
          <textarea name="stages" aria-describedby="role-approval-help" />
        </label>
        <p id="role-approval-help">
          Enter one stage per line as approval count followed by reviewer codes,
          such as 2: AB1234, CD5678. A blank policy requires no approval for new
          requests.
        </p>
        <label>
          <input type="checkbox" name="cancel_pending" />
          Cancel pending requests for the selected operation in this business
        </label>
        <button disabled={busy}>Save approval policy</button>
      </form>
      <button disabled={busy} onClick={() => run(() => load(1))}>
        Load pending approvals
      </button>
      <ul aria-label="Pending role changes">
        {items.map((item) => (
          <li key={item.id}>
            {item.assign ? "Assign" : "Remove"} {item.role} · {item.code} ·
            Stage {item.stage + 1}
            <button disabled={busy} onClick={() => decide(item.id, "approve")}>
              Approve
            </button>
            <button disabled={busy} onClick={() => decide(item.id, "reject")}>
              Reject
            </button>
          </li>
        ))}
      </ul>
      {page > 1 && (
        <button disabled={busy} onClick={() => run(() => load(page - 1))}>
          Previous approvals
        </button>
      )}
      {next && (
        <button disabled={busy} onClick={() => run(() => load(page + 1))}>
          Next approvals
        </button>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
