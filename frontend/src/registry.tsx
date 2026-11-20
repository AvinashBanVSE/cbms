import { useEffect, useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface Service {
  id: string;
  name: string;
  purpose: string;
}
interface Page {
  data: Service[];
  next_page_url: string | null;
}

export function ServiceRegistry({
  categories,
}: {
  categories: Record<string, string>;
}) {
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<Page>();
  const [selected, setSelected] = useState("");
  const [message, setMessage] = useState("");
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    let active = true;
    setResult(undefined);
    portalRequest("services?page=" + page)
      .then((r) => r.json())
      .then((value) => {
        if (active) setResult(value);
      })
      .catch((e: Error) => {
        if (active) setMessage(e.message);
      });
    return () => {
      active = false;
    };
  }, [page, revision]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (saving) return;
    const form = event.currentTarget;
    const values = new FormData(form);
    const base = {
      name: String(values.get("name")),
      purpose: String(values.get("purpose")),
    };
    const capability = form.dataset.kind === "capability";
    setSaving(true);
    try {
      if (capability)
        await portalRequest("services/" + selected + "/capabilities", base);
      else
        await portalRequest("services", {
          ...base,
          category: String(values.get("category")),
          scopes: values.getAll("scopes").map(String),
        });
      setMessage(capability ? "Capability registered." : "Service registered.");
      form.reset();
      setRevision((value) => value + 1);
    } catch (e) {
      setMessage((e as Error).message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <p role="status">{message}</p>
      <details>
        <summary>Register service</summary>
        <form onSubmit={save}>
          <label>
            Category
            <select name="category">
              {Object.entries(categories).map(([id, name]) => (
                <option key={id} value={id}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Service name
            <input name="name" maxLength={100} required />
          </label>
          <label>
            Purpose
            <textarea name="purpose" maxLength={4000} required />
          </label>
          <label>
            Applicable scopes
            <select multiple name="scopes" required>
              {[
                "Ecosystem-wide",
                "Service Provider-scoped",
                "Tenant-scoped",
                "Business-context-scoped",
              ].map((s) => (
                <option key={s}>{s}</option>
              ))}
            </select>
          </label>
          <button disabled={saving}>Register service</button>
        </form>
      </details>
      {result && (
        <>
          <ul>
            {result.data.map((s) => (
              <li key={s.id}>
                <button onClick={() => setSelected(s.id)}>{s.name}</button>{" "}
                {s.purpose}
              </li>
            ))}
          </ul>
          {!result.data.length && <p>No services registered.</p>}
          {page > 1 && (
            <button
              onClick={() => {
                setSelected("");
                setPage(page - 1);
              }}
            >
              Previous
            </button>
          )}
          {result.next_page_url && (
            <button
              onClick={() => {
                setSelected("");
                setPage(page + 1);
              }}
            >
              Next
            </button>
          )}
        </>
      )}
      {selected && (
        <form key={selected} data-kind="capability" onSubmit={save}>
          <h2>
            Register capability for{" "}
            {result?.data.find((service) => service.id === selected)?.name}
          </h2>
          <label>
            Capability name
            <input name="name" maxLength={100} required />
          </label>
          <label>
            Purpose
            <textarea name="purpose" maxLength={4000} required />
          </label>
          <button disabled={saving}>Register capability</button>
        </form>
      )}
    </>
  );
}
