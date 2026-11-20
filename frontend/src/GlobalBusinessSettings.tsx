import { useEffect, useState, type FormEvent } from "react";
import { BusinessTimePresentation } from "./BusinessTimePresentation";
import { portalRequest } from "./api";

const unitLabels = {
  mass: "Mass",
  length: "Length",
  volume: "Volume",
  area: "Area",
};
const addressLabels: Record<string, string> = {
  locality: "Locality",
  district: "District",
  state_or_union_territory: "State or Union Territory",
  pin_code: "PIN code",
};

interface Settings {
  reference_data?: {
    units: Record<keyof typeof unitLabels, string[]>;
    address_fields: string[];
  };
  stable_core: {
    country: string;
    currency: string;
    time_zone: string;
    number_grouping: string;
    calendar: string;
    measurement_system: string;
  };
  platform_defaults: {
    language: string;
    financial_year_start: string;
    financial_year_end: string;
  };
}

const monthDay = new Intl.DateTimeFormat("en-IN", {
  day: "numeric",
  month: "long",
  timeZone: "UTC",
});
const regionNames = new Intl.DisplayNames(["en-IN"], { type: "region" });
const languageNames = new Intl.DisplayNames(["en-IN"], { type: "language" });

export function GlobalBusinessSettings() {
  const [settings, setSettings] = useState<Settings>();
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    void portalRequest("global-business-settings")
      .then((response) => response.json())
      .then((value: Settings) => {
        if (active) setSettings(value);
      })
      .catch((failure: Error) => {
        if (active) setError(failure.message);
      });
    return () => {
      active = false;
    };
  }, []);

  if (error) return <p role="alert">{error}</p>;
  if (!settings) return <p role="status">Loading business settings…</p>;

  const { stable_core: stable, platform_defaults: defaults } = settings;
  const year = [defaults.financial_year_start, defaults.financial_year_end]
    .map((date) => monthDay.format(new Date("2000-" + date + "T00:00:00Z")))
    .join(" to ");

  return (
    <>
      <h2>Fixed platform settings</h2>
      <dl>
        <dt>Country</dt>
        <dd>{regionNames.of(stable.country)}</dd>
        <dt>Currency</dt>
        <dd>{stable.currency}</dd>
        <dt>Business time zone</dt>
        <dd>{stable.time_zone}</dd>
        <dt>Number grouping</dt>
        <dd>{stable.number_grouping}</dd>
        <dt>Calendar</dt>
        <dd>{stable.calendar}</dd>
        <dt>Measurement system</dt>
        <dd>{stable.measurement_system}</dd>
      </dl>
      <h2>Platform defaults</h2>
      <dl>
        <dt>Language</dt>
        <dd>{languageNames.of(defaults.language)}</dd>
        <dt>Financial year</dt>
        <dd>{year}</dd>
      </dl>
      {settings.reference_data && (
        <>
          <h2>Reference units</h2>
          <dl>
            {Object.entries(unitLabels).map(([key, label]) => (
              <div key={key}>
                <dt>{label}</dt>
                <dd>
                  {settings.reference_data?.units[
                    key as keyof typeof unitLabels
                  ].join(", ")}
                </dd>
              </div>
            ))}
          </dl>
          <ReferenceUnitConversion units={settings.reference_data.units} />
          <h2>Indian address reference fields</h2>
          <PostalLocations />
          <ul>
            {settings.reference_data.address_fields.map((field) => (
              <li key={field}>{addressLabels[field]}</li>
            ))}
          </ul>
        </>
      )}
      <BusinessTimePresentation timeZone={stable.time_zone} />
    </>
  );
}

function ReferenceUnitConversion({
  units,
}: {
  units: NonNullable<Settings["reference_data"]>["units"];
}) {
  const [dimension, setDimension] = useState<keyof typeof unitLabels>("mass");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);

  async function convert(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const query = new URLSearchParams();
    for (const name of ["quantity", "from", "to"]) {
      query.set(name, String(data.get(name)));
    }
    setBusy(true);
    setNotice("");
    try {
      const response = await portalRequest(
        "global-business-settings/unit-conversion?" + query,
      );
      const result: { quantity: number; unit: string } = await response.json();
      const quantity = new Intl.NumberFormat("en-IN", {
        maximumSignificantDigits: 12,
      }).format(result.quantity);
      setNotice(quantity + " " + result.unit);
    } catch (error) {
      setNotice((error as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form data-operation="unit-conversion" onSubmit={convert}>
      <fieldset disabled={busy}>
        <legend>Convert reference units</legend>
        <label>
          Measurement
          <select
            value={dimension}
            onChange={(event) => {
              setDimension(event.target.value as keyof typeof unitLabels);
              setNotice("");
            }}
          >
            {Object.entries(unitLabels).map(([key, label]) => (
              <option key={key} value={key}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Quantity
          <input
            name="quantity"
            type="number"
            required
            min="0"
            max="1000000000000"
            step="any"
          />
        </label>
        {["from", "to"].map((name) => (
          <label key={name}>
            {name === "from" ? "From" : "To"}
            <select key={dimension} name={name}>
              {units[dimension].map((unit) => (
                <option key={unit} value={unit}>
                  {unit}
                </option>
              ))}
            </select>
          </label>
        ))}
        <button>Convert</button>
      </fieldset>
      <output aria-live="polite">{notice}</output>
    </form>
  );
}

interface PostalLocation {
  office: string;
  district: string;
  state_or_union_territory: string;
}

function PostalLocations() {
  const [locations, setLocations] = useState<PostalLocation[]>();
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function lookup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const pin = String(
      new FormData(event.currentTarget).get("pin_code"),
    ).trim();
    setLocations(undefined);
    setError("");
    setBusy(true);
    try {
      const query = new URLSearchParams({ pin_code: pin });
      const response = await portalRequest(
        "global-business-settings/postal-locations?" + query,
      );
      const result: { data: PostalLocation[] } = await response.json();
      setLocations(result.data);
    } catch (failure) {
      setError((failure as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section aria-label="Postal directory">
      <form data-operation="postal-lookup" onSubmit={lookup}>
        <label>
          Look up a PIN code
          <input
            name="pin_code"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            required
          />
        </label>
        <button disabled={busy}>Find post offices</button>
      </form>
      {error && <p role="alert">{error}</p>}
      {locations &&
        (locations.length ? (
          <ul aria-label="Matching post offices">
            {locations.map(
              ({ office, district, state_or_union_territory: state }) => (
                <li key={JSON.stringify([office, district, state])}>
                  {office}, {district}, {state}
                </li>
              ),
            )}
          </ul>
        ) : (
          <p role="status">No post offices match this PIN code.</p>
        ))}
    </section>
  );
}
