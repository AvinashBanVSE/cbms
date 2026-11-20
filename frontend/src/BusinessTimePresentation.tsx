import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

interface ClockSettings {
  business_id: string;
  scope: "platform" | "organization" | "business";
  effective: { clock: "12-hour" | "24-hour" | null; source: string };
  upstream_lock: string | null;
  local: {
    clock: string | null;
    override_allowed: boolean;
    locked: boolean;
    version: number;
  };
  can_manage: boolean;
}

const sourceNames: Record<string, string> = {
  platform: "Main Portal",
  organization: "Organization",
  business: "Shop or Distributor",
  locale_default: "Indian locale default",
};

const endpoint = "global-business-settings/time-presentation";

export function BusinessTimePresentation({
  timeZone = "Asia/Kolkata",
  organization = false,
}: {
  timeZone?: string;
  organization?: boolean;
}) {
  const [settings, setSettings] = useState<ClockSettings>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function send(
    path: string,
    values?: Parameters<typeof portalRequest>[1],
  ) {
    setBusy(true);
    setNotice("");
    try {
      const response = await portalRequest(path, values);
      setSettings(await response.json());
      if (values) setNotice("Time display settings saved.");
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  function load(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSettings(undefined);
    if (organization) return send("organization-time-presentation");
    const id = String(new FormData(event.currentTarget).get("business")).trim();
    return send(id ? endpoint + "/" + encodeURIComponent(id) : endpoint);
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!settings) return;
    const fields = new FormData(event.currentTarget);
    const values = {
      clock: String(fields.get("clock") ?? "") || null,
      expected_version: settings.local.version,
    };
    if (organization) return send("organization-time-presentation", values);
    return send(endpoint + "/" + encodeURIComponent(settings.business_id), {
      ...values,
      override_allowed: fields.has("override_allowed"),
      locked: fields.has("locked"),
    });
  }

  async function governAccess(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!settings) return;
    const fields = new FormData(event.currentTarget);
    setBusy(true);
    setNotice("");
    try {
      await portalRequest(
        endpoint + "/" + encodeURIComponent(settings.business_id) + "/access",
        {
          code: String(fields.get("code")).trim(),
          enabled: fields.get("access") === "grant",
        },
      );
      setNotice("Organization settings access updated.");
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  const clock = settings?.effective.clock;
  const preview = new Intl.DateTimeFormat("en-IN", {
    timeStyle: "short",
    timeZone,
    ...(clock ? { hour12: clock === "12-hour" } : {}),
  }).format(new Date());

  return (
    <details>
      <summary>Business time display</summary>
      <form data-operation="load-time-presentation" onSubmit={load}>
        {!organization && (
          <>
            <label>
              Business ID
              <input name="business" />
            </label>
            <p>Leave blank for Main Portal settings.</p>
          </>
        )}
        <button disabled={busy}>Load time display settings</button>
      </form>
      {settings && (
        <>
          <p>
            Effective clock: {clock ?? "Locale default"} · Source:{" "}
            {sourceNames[settings.effective.source]} · Preview: {preview}
          </p>
          {settings.upstream_lock && (
            <p>
              Inherited value locked by {sourceNames[settings.upstream_lock]}.
            </p>
          )}
          {settings.can_manage && !settings.upstream_lock && (
            <form
              key={settings.business_id + "/" + settings.local.version}
              data-operation="save-time-presentation"
              onSubmit={save}
            >
              <label>
                Clock format
                <select name="clock" defaultValue={settings.local.clock ?? ""}>
                  <option value="">Use governing default</option>
                  <option value="12-hour">12-hour</option>
                  <option value="24-hour">24-hour</option>
                </select>
              </label>
              {!organization && settings.scope !== "platform" && (
                <label>
                  <input
                    type="checkbox"
                    name="override_allowed"
                    defaultChecked={settings.local.override_allowed}
                  />
                  Allow a local override
                </label>
              )}
              {!organization && settings.scope !== "business" && (
                <label>
                  <input
                    type="checkbox"
                    name="locked"
                    defaultChecked={settings.local.locked}
                  />
                  Lock the effective format for subordinate businesses
                </label>
              )}
              <button disabled={busy}>Save time display settings</button>
            </form>
          )}
          {!organization &&
            settings.can_manage &&
            settings.scope === "organization" && (
              <form
                data-operation="time-presentation-access"
                onSubmit={governAccess}
              >
                <label>
                  Organization user code
                  <input name="code" pattern="[A-Z]{2}[0-9]{4}" required />
                </label>
                <label>
                  Settings access
                  <select name="access">
                    <option value="grant">Grant</option>
                    <option value="revoke">Revoke</option>
                  </select>
                </label>
                <button disabled={busy}>
                  Update Organization settings access
                </button>
              </form>
            )}
        </>
      )}
      <p role="status">{notice}</p>
    </details>
  );
}
