import { useEffect, useState } from "react";
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
          <h2>Indian address reference fields</h2>
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
