import { useState, type FormEvent } from "react";
import { portalRequest } from "./api";

type Field = {
  name: string;
  label: string;
  type?: string;
  options?: [string, string][];
  optional?: boolean;
};
type Section =
  | "accounts"
  | "entitlements"
  | "plans"
  | "versions"
  | "subscriptions";
const sections: Record<Section, { label: string; fields: Field[] }> = {
  accounts: {
    label: "Commercial accounts",
    fields: [
      { name: "tenant_id", label: "Organization Tenant ID" },
      { name: "provider_id", label: "Service provider ID" },
      { name: "billing_party", label: "Contracting and billing party" },
    ],
  },
  entitlements: {
    label: "Feature entitlements",
    fields: [
      { name: "code", label: "Entitlement Code" },
      { name: "name", label: "Feature name" },
    ],
  },
  plans: {
    label: "Plans",
    fields: [
      { name: "provider_id", label: "Service provider ID" },
      { name: "name", label: "Plan name" },
      {
        name: "family",
        label: "Plan family",
        options: [
          ["operator", "Operator"],
          ["tenant", "Tenant"],
          ["dealer", "Dealer"],
        ],
      },
    ],
  },
  versions: {
    label: "Published Plan Versions",
    fields: [
      { name: "plan_id", label: "Plan ID" },
      {
        name: "amount_paise",
        label: "Term price in paise (INR)",
        type: "number",
      },
      { name: "term_days", label: "Term in calendar days", type: "number" },
      {
        name: "entitlements",
        label: "Feature entitlement codes (comma separated)",
        optional: true,
      },
    ],
  },
  subscriptions: {
    label: "Subscriptions",
    fields: [
      { name: "account_id", label: "Commercial Account ID" },
      { name: "plan_version_id", label: "Tenant Plan Version ID" },
      { name: "starts_on", label: "Starts on (India calendar)", type: "date" },
      {
        name: "coverage_mode",
        label: "Coverage",
        options: [
          ["organization", "Organization only"],
          ["selected", "Selected businesses"],
          ["network", "Current Organization network"],
          ["future_network", "Current and future Organization network"],
        ],
      },
      {
        name: "business_ids",
        label: "Business IDs for selected coverage (comma separated)",
        optional: true,
      },
    ],
  },
};

interface Listing {
  data: Record<string, unknown>[];
  next_page_url: string | null;
}

export function CommercialWorkspace() {
  const [section, setSection] = useState<Section>("accounts");
  const [listing, setListing] = useState<Listing>();
  const [page, setPage] = useState(1);
  const [notice, setNotice] = useState("");
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
    setListing(
      await (
        await portalRequest("commercial?section=" + section + "&page=" + number)
      ).json(),
    );
    setPage(number);
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    const body: Record<string, string | string[]> = Object.fromEntries(
      Array.from(fields.entries(), ([name, value]) => [
        name,
        String(value).trim(),
      ]),
    );
    const plan = String(body.plan_id ?? "");
    const split = (value: unknown) =>
      String(value ?? "")
        .split(",")
        .map((entry) => entry.trim())
        .filter(Boolean);
    if (section === "versions") {
      delete body.plan_id;
      body.entitlements = split(body.entitlements);
    }
    if (section === "subscriptions") {
      body.renewal_mode = "manual";
      body.business_ids = split(body.business_ids);
      if (body.coverage_mode !== "selected" && body.business_ids.length === 0)
        delete body.business_ids;
    }
    return run(async () => {
      const path =
        section === "versions"
          ? "commercial/plans/" + encodeURIComponent(plan) + "/versions"
          : "commercial/" + section;
      const result = await (await portalRequest(path, body)).json();
      await load(1);
      setNotice(
        (result.state ? result.state + " · " : "") +
          "Saved: " +
          (result.id ?? result.code),
      );
      form.reset();
    });
  }

  return (
    <>
      <label>
        Commercial section
        <select
          value={section}
          disabled={busy}
          onChange={(event) => {
            setSection(event.target.value as Section);
            setListing(undefined);
            setPage(1);
            setNotice("");
          }}
        >
          {Object.entries(sections).map(([key, value]) => (
            <option key={key} value={key}>
              {value.label}
            </option>
          ))}
        </select>
      </label>
      <form key={section} data-operation="commercial-create" onSubmit={save}>
        {sections[section].fields.map((field) => (
          <label key={field.name}>
            {field.label}
            {field.options ? (
              <select name={field.name}>
                {field.options.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            ) : (
              <input
                name={field.name}
                type={field.type ?? "text"}
                required={!field.optional}
              />
            )}
          </label>
        ))}
        {section === "subscriptions" && (
          <p>
            Renewal is manual. The subscription is created in Pending
            Activation.
          </p>
        )}
        <button disabled={busy}>
          {section === "versions" ? "Publish Plan Version" : "Create record"}
        </button>
      </form>
      <button disabled={busy} onClick={() => run(() => load(1))}>
        Load commercial records
      </button>
      {listing && (
        <>
          <ul aria-label="Commercial records">
            {listing.data.map((item) => (
              <li key={String(item.id ?? item.code)}>
                <dl>
                  {Object.entries(item).map(([name, value]) => (
                    <div key={name}>
                      <dt>{name.replaceAll("_", " ")}</dt>
                      <dd>
                        {typeof value === "object"
                          ? JSON.stringify(value)
                          : String(value)}
                      </dd>
                    </div>
                  ))}
                </dl>
              </li>
            ))}
          </ul>
          {page > 1 && (
            <button disabled={busy} onClick={() => run(() => load(page - 1))}>
              Previous records
            </button>
          )}
          {listing.next_page_url && (
            <button disabled={busy} onClick={() => run(() => load(page + 1))}>
              Next records
            </button>
          )}
        </>
      )}
      <p role="status">{notice}</p>
    </>
  );
}
