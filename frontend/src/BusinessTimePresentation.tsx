import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";
import { formatPresentedDate } from "./indiaTime";

export type PresentationSetting =
  | "clock"
  | "date_format"
  | "week_start"
  | "negative_amount";

const options: Record<
  PresentationSetting,
  { label: string; values: Record<string, string> }
> = {
  clock: {
    label: "Clock format",
    values: { "12-hour": "12-hour", "24-hour": "24-hour" },
  },
  date_format: {
    label: "Date format",
    values: { "day-month-year": "DD/MM/YYYY", "year-month-day": "YYYY-MM-DD" },
  },
  negative_amount: {
    label: "Negative amount display",
    values: { minus: "Minus sign", parentheses: "Parentheses" },
  },
  week_start: {
    label: "First day of the week",
    values: {
      monday: "Monday",
      tuesday: "Tuesday",
      wednesday: "Wednesday",
      thursday: "Thursday",
      friday: "Friday",
      saturday: "Saturday",
      sunday: "Sunday",
    },
  },
};

interface ClockSettings {
  business_id: string;
  scope: "platform" | "organization" | "business";
  effective: Partial<Record<PresentationSetting, string | null>> & {
    source: string;
  };
  upstream_lock: string | null;
  local: Partial<Record<PresentationSetting, string | null>> & {
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

export function BusinessTimePresentation({
  timeZone = "Asia/Kolkata",
  organization = false,
  settingsKeys = ["clock", "date_format", "week_start", "negative_amount"],
}: {
  timeZone?: string;
  organization?: boolean;
  settingsKeys?: PresentationSetting[];
}) {
  const [setting, setSetting] = useState<PresentationSetting>(
    settingsKeys[0] ?? "clock",
  );
  const [settings, setSettings] = useState<ClockSettings>();
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const preferencePath =
    setting === "clock"
      ? "time-presentation"
      : (setting === "negative_amount"
          ? "number-presentation/"
          : "calendar-presentation/") + setting;
  const endpoint = "global-business-settings/" + preferencePath;
  const organizationEndpoint = "organization-" + preferencePath;

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
    if (organization) return send(organizationEndpoint);
    const id = String(new FormData(event.currentTarget).get("business")).trim();
    return send(id ? endpoint + "/" + encodeURIComponent(id) : endpoint);
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!settings) return;
    const fields = new FormData(event.currentTarget);
    const values = {
      [setting]: String(fields.get(setting) ?? "") || null,
      expected_version: settings.local.version,
    };
    if (organization) return send(organizationEndpoint, values);
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

  const effective = settings?.effective[setting];
  const definition = options[setting];
  const preview =
    effective && setting === "date_format"
      ? formatPresentedDate(new Date(), effective, timeZone)
      : setting === "clock"
        ? new Intl.DateTimeFormat("en-IN", {
            timeStyle: "short",
            timeZone,
            ...(effective ? { hour12: effective === "12-hour" } : {}),
          }).format(new Date())
        : null;

  return (
    <details>
      <summary>Business time display</summary>
      <form data-operation="load-time-presentation" onSubmit={load}>
        {settingsKeys.length > 1 && (
          <label>
            Presentation setting
            <select
              name="presentation_setting"
              value={setting}
              onChange={(event) => {
                setSetting(event.target.value as PresentationSetting);
                setSettings(undefined);
                setNotice("");
              }}
              disabled={busy}
            >
              {settingsKeys.map((key) => (
                <option key={key} value={key}>
                  {options[key].label}
                </option>
              ))}
            </select>
          </label>
        )}
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
            {setting === "clock" ? "Effective clock" : definition.label}:{" "}
            {effective ? definition.values[effective] : "Locale default"} ·
            Source: {sourceNames[settings.effective.source]}
            {preview && " · Preview: " + preview}
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
                {definition.label}
                <select
                  name={setting}
                  defaultValue={settings.local[setting] ?? ""}
                >
                  <option value="">Use governing default</option>
                  {Object.entries(definition.values).map(([key, label]) => (
                    <option key={key} value={key}>
                      {label}
                    </option>
                  ))}
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
