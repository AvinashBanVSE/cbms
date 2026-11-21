import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";
import { indiaTime } from "./indiaTime";

export interface MappingContext {
  id: string;
  kind: "Shop" | "Distributor";
  professional_name: string;
  action: string;
}

const labels: Record<string, string> = {
  "mapping.initiate": "Generate Shop code",
  "mapping.verify": "Verify Shop code",
  "mapping.confirm": "Confirm Distributor code",
  "mapping.end": "Revoke code or end mapping",
  "mapping.history.view": "View Mapping History",
};

interface MappingHistory {
  mapping_type?: { code: string; name: string };
  data: { id: string; action: string; recorded_at: string }[];
  next_page_url: string | null;
}

interface MappingOperations {
  allowed_transactions: Record<string, { fields: string[] }>;
  shared_data_fields: string[];
}

export function MappingWorkspace({ contexts }: { contexts: MappingContext[] }) {
  const [feedback, setFeedback] = useState("");
  const [selection, setSelection] = useState(0);
  const [busy, setBusy] = useState(false);
  const [history, setHistory] = useState<{
    path: string;
    number: number;
    page: MappingHistory;
  }>();
  const [operations, setOperations] = useState<MappingOperations>();
  const selected = contexts[selection];
  const action = selected.action;
  const creating = action === "mapping.initiate";
  const ending = action === "mapping.end";
  const verifying = action === "mapping.verify";
  const reading = action === "mapping.history.view";

  async function loadHistory(path: string, number: number) {
    setBusy(true);
    setFeedback("");
    try {
      const [page, catalogue] = await Promise.all([
        (await portalRequest(path + "?page=" + number)).json() as Promise<MappingHistory>,
        (await portalRequest(
          path.replace(/\/history$/, "/operations"),
        )).json() as Promise<{ mapping: MappingOperations }>,
      ]);
      setHistory({ path, number, page });
      setOperations(catalogue.mapping);
    } catch (error) {
      setFeedback((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const id = encodeURIComponent(String(data.get("mapping_id") ?? "").trim());
    if (reading) {
      setHistory(undefined);
      return loadHistory(
        "businesses/" + selected.id + "/mappings/" + id + "/history",
        1,
      );
    }
    const party = String(data.get("party") ?? "").trim();
    const code = String(data.get("code") ?? "").trim();
    const path = creating
      ? "shops/" + selected.id + "/mappings"
      : ending
        ? "businesses/" + selected.id + "/mappings/" + id + "/end"
        : verifying
          ? "distributors/" + selected.id + "/mappings/verify"
          : "shops/" + selected.id + "/mappings/confirm";
    const body: Record<string, string> = creating
      ? { distributor_id: party }
      : ending
        ? {}
        : verifying
          ? { shop_id: party, code }
          : { code };
    setBusy(true);
    setFeedback("");
    try {
      const result = await (await portalRequest(path, body)).json();
      form.reset();
      setFeedback(
        result.code
          ? (creating ? "Shop ID: " + selected.id + " | " : "") +
              "One-time code: " +
              result.code +
              " | Mapping ID (for revocation): " +
              result.id
          : "Mapping status: " + result.state,
      );
    } catch (error) {
      setFeedback((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <label>
        Business and action
        <select
          value={selection}
          disabled={busy}
          onChange={(event) => {
            setSelection(Number(event.target.value));
            setFeedback("");
            setHistory(undefined);
            setOperations(undefined);
          }}
        >
          {contexts.map((context, index) => (
            <option key={context.id + context.action} value={index}>
              {context.professional_name} · {labels[context.action]}
            </option>
          ))}
        </select>
      </label>
      <form key={selection} onSubmit={submit}>
        {(ending || reading) && (
          <label>
            Mapping ID
            <input name="mapping_id" required />
          </label>
        )}
        {(creating || verifying) && (
          <label>
            {verifying ? "Shop ID" : "Distributor ID"}
            <input name="party" required />
          </label>
        )}
        {!creating && !ending && !reading && (
          <label>
            One-time code
            <input name="code" type="password" required />
          </label>
        )}
        <button disabled={busy}>{labels[action]}</button>
      </form>
      {history && (
        <>
          {history.page.mapping_type && <p>Mapping type: {history.page.mapping_type.name}</p>}
          {operations && (
            <section aria-label="Authorized mapped operations">
              {Object.keys(operations.allowed_transactions).length === 0 ? (
                <p>
                  No transactions or data fields are authorized through this
                  mapping type. A mapping confers no access to unlisted data.
                </p>
              ) : (
                <>
                  <p>Authorized transactions</p>
                  <ul>
                    {Object.entries(operations.allowed_transactions).map(
                      ([verb, { fields }]) => (
                        <li key={verb}>
                          {verb} · fields: {fields.join(", ")}
                        </li>
                      ),
                    )}
                  </ul>
                  <p>Shared data fields: {operations.shared_data_fields.join(", ")}</p>
                </>
              )}
            </section>
          )}
          <ol aria-label="Mapping History">
            {history.page.data.map((entry) => (
              <li key={entry.id}>
                {entry.action} ·{" "}
                <time dateTime={entry.recorded_at}>
                  {indiaTime.format(new Date(entry.recorded_at))}
                </time>
              </li>
            ))}
          </ol>
          {history.page.data.length === 0 && <p>No history on this page.</p>}
          {history.number > 1 && (
            <button
              disabled={busy}
              onClick={() => loadHistory(history.path, history.number - 1)}
            >
              Previous history page
            </button>
          )}
          {history.page.next_page_url && (
            <button
              disabled={busy}
              onClick={() => loadHistory(history.path, history.number + 1)}
            >
              Next history page
            </button>
          )}
        </>
      )}
      <p role="status">{feedback}</p>
    </>
  );
}
