import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";
import { indiaTime } from "./indiaTime";

interface AuditEvent {
  id: string;
  action: string;
  recorded_at: string;
  subject_id: string | null;
  actor_id: string | null;
}

interface AuditPage {
  data: AuditEvent[];
  next_before_id: string | null;
}

export function AuditTrail() {
  const [page, setPage] = useState<AuditPage>();
  const [filters, setFilters] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const nextBeforeId = page?.next_before_id;

  async function load(query: URLSearchParams) {
    setBusy(true);
    setNotice("");
    try {
      const result: AuditPage = await (
        await portalRequest("audit-trail?" + query)
      ).json();
      setPage(result);
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const query = new URLSearchParams();
    for (const [key, entry] of new FormData(event.currentTarget).entries()) {
      const value = String(entry).trim();
      if (value) query.set(key, value);
    }
    setFilters(query.toString());
    setPage(undefined);
    return load(query);
  }

  return (
    <>
      <form data-operation="audit-search" onSubmit={search}>
        <label>
          Action
          <input name="action" maxLength={120} />
        </label>
        <label>
          Subject ID
          <input name="subject_id" />
        </label>
        <button disabled={busy}>Load Audit Trail</button>
      </form>
      {page &&
        (page.data.length ? (
          <>
            <ul aria-label="Audit events">
              {page.data.map((event) => (
                <li key={event.id}>
                  <strong>{event.action}</strong>
                  <dl>
                    <dt>Recorded in India time</dt>
                    <dd>
                      <time dateTime={event.recorded_at}>
                        {indiaTime.format(new Date(event.recorded_at))}
                      </time>
                    </dd>
                    <dt>Actor ID</dt>
                    <dd>{event.actor_id ?? "Unavailable"}</dd>
                    <dt>Subject ID</dt>
                    <dd>{event.subject_id ?? "None"}</dd>
                  </dl>
                </li>
              ))}
            </ul>
            {nextBeforeId && (
              <button
                disabled={busy}
                onClick={() => {
                  const query = new URLSearchParams(filters);
                  query.set("before_id", nextBeforeId);
                  return load(query);
                }}
              >
                Older events
              </button>
            )}
          </>
        ) : (
          <p>No events match these filters.</p>
        ))}
      <p role="status">{notice}</p>
    </>
  );
}
