import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

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
};

export function MappingWorkspace({ contexts }: { contexts: MappingContext[] }) {
  const [feedback, setFeedback] = useState("");
  const [selection, setSelection] = useState(0);
  const [busy, setBusy] = useState(false);
  const selected = contexts[selection];
  const action = selected.action;
  const creating = action === "mapping.initiate";
  const ending = action === "mapping.end";
  const verifying = action === "mapping.verify";

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const id = encodeURIComponent(String(data.get("mapping_id") ?? "").trim());
    const party = String(data.get("party") ?? "").trim();
    const code = String(data.get("code") ?? "").trim();
    const path = creating
      ? "shops/" + selected.id + "/mappings"
      : ending
        ? "businesses/" + selected.id + "/mappings/" + id + "/end"
        : verifying
          ? "distributors/" + selected.id + "/mappings/" + id + "/verify"
          : "shops/" + selected.id + "/mappings/" + id + "/confirm";
    const body: Record<string, string> = creating
      ? { distributor_id: party }
      : ending
        ? {}
        : verifying
          ? { shop_id: party, code }
          : { distributor_id: party, code };
    setBusy(true);
    setFeedback("");
    try {
      const result = await (await portalRequest(path, body)).json();
      form.reset();
      setFeedback(
        result.code
          ? "Mapping ID: " + result.id + " | One-time code: " + result.code
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
        {!creating && (
          <label>
            Mapping ID
            <input name="mapping_id" required />
          </label>
        )}
        {!ending && (
          <label>
            {verifying ? "Shop ID" : "Distributor ID"}
            <input name="party" required />
          </label>
        )}
        {!creating && !ending && (
          <label>
            One-time code
            <input name="code" type="password" required />
          </label>
        )}
        <button disabled={busy}>{labels[action]}</button>
      </form>
      <p role="status">{feedback}</p>
    </>
  );
}
